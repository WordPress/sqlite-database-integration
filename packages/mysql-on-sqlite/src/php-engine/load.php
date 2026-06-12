<?php

/**
 * Load the pure-PHP database engine ("WP_PHP_Engine").
 *
 * An SQLite-compatible database engine implemented entirely in PHP, with no
 * dependency on the pdo_sqlite or sqlite3 extensions. The engine is exposed
 * through a PDO-compatible facade (WP_PHP_Engine_PDO) that can be passed
 * anywhere a PDO SQLite connection is expected:
 *
 *     $pdo        = new WP_PHP_Engine_PDO( 'php-engine:/path/to/database' );
 *     $connection = new WP_SQLite_Connection( array( 'pdo' => $pdo ) );
 *     $driver     = new WP_SQLite_Driver( $connection, 'wp' );
 */

require_once __DIR__ . '/class-wp-php-engine-lexer.php';
require_once __DIR__ . '/class-wp-php-engine-parser.php';
require_once __DIR__ . '/class-wp-php-engine-values.php';
require_once __DIR__ . '/class-wp-php-engine-functions.php';
require_once __DIR__ . '/class-wp-php-engine-evaluator.php';
require_once __DIR__ . '/class-wp-php-engine.php';
require_once __DIR__ . '/class-wp-php-engine-pdo.php';
require_once __DIR__ . '/class-wp-php-engine-pdo-statement.php';
