<?php

require_once __DIR__ . '/../WP_SQLite_Driver_Query_Tests.php';

/**
 * Runs the SQLite driver query test suite against the pure-PHP database
 * engine instead of a real SQLite database.
 */
class WP_PHP_Engine_Driver_Query_Tests extends WP_SQLite_Driver_Query_Tests {
	/**
	 * Create a pure-PHP database engine connection.
	 */
	protected function create_pdo(): PDO {
		return new WP_PHP_Engine_PDO( 'php-engine::memory:' );
	}
}
