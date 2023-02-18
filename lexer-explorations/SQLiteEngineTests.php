<?php

use PHPUnit\Framework\TestCase;

class SQLiteEngineTests extends TestCase {

	public static function setUpBeforeClass(): void {
		if(!defined('FQDB')) {
			define( 'FQDB', ':memory:' );
			define( 'FQDBDIR', __DIR__ . '/../testdb' );
		}
		if(!class_exists('WP_SQLite_PDO_Engine')) {
			error_reporting(E_ALL & ~E_DEPRECATED);
			require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-pdo-engine.php';
		}
		if(!isset($GLOBALS['table_prefix'])){
			$GLOBALS['table_prefix'] = 'wptests_';
		}
		if(!isset($GLOBALS['wpdb'])){
			$GLOBALS['wpdb'] = new stdClass();
			$GLOBALS['wpdb']->suppress_errors = false;
			$GLOBALS['wpdb']->show_errors = true;
		}
	}

	public function testSimpleQuery() {
		$engine = new WP_SQLite_PDO_Engine( );
		$engine->query(
			"CREATE TABLE wptests_dummy (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				option_name TEXT NOT NULL default '',
				option_value TEXT NOT NULL default ''
			);"
		);
		$engine->query(
			"INSERT INTO wptests_dummy (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);
		$engine->query(
			"INSERT INTO wptests_dummy (option_name, option_value) VALUES ('transient', '1');"
		);

		$engine->query("DELETE FROM wptests_dummy WHERE option_name  REGEXP '^rss_.+$'");

		$engine->query('SELECT * FROM wptests_dummy');

		$this->assertCount(
			1,
			$engine->get_query_results()
		);
	}

}
