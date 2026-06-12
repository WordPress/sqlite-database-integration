<?php

require_once __DIR__ . '/../WP_SQLite_Driver_Tests.php';

/**
 * Runs the full SQLite driver test suite against the pure-PHP database
 * engine instead of a real SQLite database.
 */
class WP_PHP_Engine_Driver_Tests extends WP_SQLite_Driver_Tests {
	/**
	 * Create a pure-PHP database engine connection.
	 */
	protected function create_pdo(): PDO {
		return new WP_PHP_Engine_PDO( 'php-engine::memory:' );
	}
}
