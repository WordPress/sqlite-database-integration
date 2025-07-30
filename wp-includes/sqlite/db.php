<?php
/**
 * Main integration file.
 *
 * @package wp-sqlite-integration
 * @since 1.0.0
 */

/**
 * Load the "SQLITE_DRIVER_VERSION" constant.
 */
require_once dirname( __DIR__, 2 ) . '/version.php';

// Require the constants file.
require_once dirname( __DIR__, 2 ) . '/constants.php';

// Bail early if DB_ENGINE is not defined as sqlite.
if ( ! defined( 'DB_ENGINE' ) || 'sqlite' !== DB_ENGINE ) {
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

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	wp_die(
		new WP_Error(
			'pdo_driver_not_loaded',
			sprintf(
				'<h1>%1$s</h1><p>%2$s</p>',
				'PDO Driver for SQLite is missing',
				'Your PHP installation appears not to have the right PDO drivers loaded. These are required for this version of WordPress and the type of database you have specified.'
			)
		),
		'PDO Driver for SQLite is missing.'
	);
}

if ( defined( 'WP_SQLITE_AST_DRIVER' ) && WP_SQLITE_AST_DRIVER ) {
	require_once __DIR__ . '/../parser/class-wp-parser-grammar.php';
	require_once __DIR__ . '/../parser/class-wp-parser.php';
	require_once __DIR__ . '/../parser/class-wp-parser-node.php';
	require_once __DIR__ . '/../parser/class-wp-parser-token.php';
	require_once __DIR__ . '/../mysql/class-wp-mysql-token.php';
	require_once __DIR__ . '/../mysql/class-wp-mysql-lexer.php';
	require_once __DIR__ . '/../mysql/class-wp-mysql-parser.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-connection.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-configurator.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-driver.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-driver-exception.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-information-schema-builder.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-information-schema-exception.php';
	require_once __DIR__ . '/../sqlite-ast/class-wp-sqlite-information-schema-reconstructor.php';
	require_once __DIR__ . '/class-wp-sqlite-pdo-user-defined-functions.php';
	require_once __DIR__ . '/install-functions.php';
	require_once __DIR__ . '/../sqlite-ast/class-wpdb-sqlite.php';
} else {
	require_once __DIR__ . '/class-wp-sqlite-lexer.php';
	require_once __DIR__ . '/class-wp-sqlite-query-rewriter.php';
	require_once __DIR__ . '/class-wp-sqlite-translator.php';
	require_once __DIR__ . '/class-wp-sqlite-token.php';
	require_once __DIR__ . '/class-wp-sqlite-pdo-user-defined-functions.php';
	require_once __DIR__ . '/class-wp-sqlite-db.php';
	require_once __DIR__ . '/install-functions.php';
}

/*
 * Debug: Cross-check with MySQL.
 * This is for debugging purpose only and requires files
 * that are present in the GitHub repository
 * but not the plugin published on WordPress.org.
 */
$crosscheck_tests_file_path = dirname( __DIR__, 2 ) . '/tests/class-wp-sqlite-crosscheck-db.php';
if ( defined( 'SQLITE_DEBUG_CROSSCHECK' ) && SQLITE_DEBUG_CROSSCHECK && file_exists( $crosscheck_tests_file_path ) ) {
	require_once $crosscheck_tests_file_path;
	$GLOBALS['wpdb'] = new WP_SQLite_Crosscheck_DB( DB_NAME );
} elseif ( defined( 'WP_SQLITE_AST_DRIVER' ) && WP_SQLITE_AST_DRIVER ) {
	$GLOBALS['wpdb'] = new WPDB_SQLite( defined( 'DB_NAME' ) ? DB_NAME : '' );

	// Boot the Query Monitor plugin if it is active.
	require_once dirname( __DIR__, 2 ) . '/integrations/query-monitor/boot.php';
} else {
	$GLOBALS['wpdb'] = new WP_SQLite_DB( defined( 'DB_NAME' ) ? DB_NAME : '' );

	// Boot the Query Monitor plugin if it is active.
	require_once dirname( __DIR__, 2 ) . '/integrations/query-monitor/boot.php';
}
