<?php

require_once __DIR__ . '/class-wpdb-test-double.php';
require_once __DIR__ . '/../../../plugin-sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-db.php';

class WP_SQLite_DB_Test_Double extends WP_SQLite_DB {
	public function __construct( WP_MySQL_On_SQLite $driver ) {
		$this->dbh = $driver;
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}
}
