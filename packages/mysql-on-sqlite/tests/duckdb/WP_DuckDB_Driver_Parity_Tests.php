<?php

require_once __DIR__ . '/WP_DuckDB_Differential_TestCase.php';

/**
 * Differential tests that compare supported DuckDB behavior against SQLite.
 *
 * @group duckdb
 * @group duckdb-parity
 */
class WP_DuckDB_Driver_Parity_Tests extends WP_DuckDB_Differential_TestCase {
	public function test_select_literals_and_aliases_match_sqlite(): void {
		$this->assertParityRows( "SELECT 1 AS id, 'Ada' AS name" );
	}

	public function test_common_scalar_functions_match_sqlite(): void {
		$this->assertParityRows( "SELECT GREATEST('a', 'b') AS greatest_value, LEAST('a', 'b') AS least_value" );
		$this->assertParityRows( "SELECT SUBSTR('abcdef', 2, 3) AS short_substr, SUBSTRING('abcdef', 2, 3) AS long_substring" );
	}

	public function test_create_insert_select_update_delete_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1), (2, 'second', 2)",
			)
		);

		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "UPDATE items SET hits = 3 WHERE name = 'old'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "DELETE FROM items WHERE name = 'second'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_insert_set_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
			)
		);

		$this->assertParityRowCount( "INSERT INTO items SET id = 1, name = 'first', hits = 2" );
		$this->assertParityRowCount( "INSERT items SET id = 2, name = 'second'" );
		$this->assertParityRowCount( "INSERT IGNORE items SET id = 2, name = 'duplicate'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_regexp_and_not_regexp_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE options (option_name VARCHAR(100))',
				"INSERT INTO options VALUES ('rss_123'), ('transient')",
			)
		);

		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name REGEXP '^rss_.+$' ORDER BY option_name" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name NOT REGEXP '^rss_.+$' ORDER BY option_name" );
	}

	public function test_replace_values_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(100) NOT NULL DEFAULT '',
					hits INTEGER NOT NULL DEFAULT 0
				)",
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)",
			)
		);

		$this->assertParityRowCount( "REPLACE INTO items (id, name, hits) VALUES (1, 'new', 2)" );
		$this->assertParityRowCount( "REPLACE items (id, name) VALUES (2, 'second')" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_on_duplicate_key_update_values_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(100) UNIQUE,
					hits INTEGER NOT NULL DEFAULT 0
				)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (1, 'renamed', 7)
			ON DUPLICATE KEY UPDATE name = VALUES(name), hits = VALUES(hits)"
		);
		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (2, 'renamed', 11)
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)"
		);
		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (3, 'third', 3)
			ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
		);
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_create_table_if_not_exists_with_secondary_index_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE IF NOT EXISTS wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					PRIMARY KEY  (option_id),
					KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM wp_options',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
	}
}
