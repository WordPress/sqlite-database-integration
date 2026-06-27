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

	public function test_select_date_time_literal_functions_match_sqlite(): void {
		foreach (
			array(
				'SELECT LENGTH(NOW()) AS value_length, SUBSTR(NOW(), 5, 1) AS date_sep, SUBSTR(NOW(), 14, 1) AS time_sep',
				'SELECT LENGTH(CURRENT_TIMESTAMP()) AS value_length, SUBSTR(CURRENT_TIMESTAMP(), 5, 1) AS date_sep, SUBSTR(CURRENT_TIMESTAMP(), 14, 1) AS time_sep',
				'SELECT LENGTH(CURDATE()) AS value_length, SUBSTR(CURDATE(), 5, 1) AS date_sep',
				'SELECT LENGTH(UTC_DATE()) AS value_length, SUBSTR(UTC_DATE(), 5, 1) AS date_sep',
				'SELECT LENGTH(UTC_TIME()) AS value_length, SUBSTR(UTC_TIME(), 3, 1) AS hour_sep',
				'SELECT LENGTH(UTC_TIMESTAMP()) AS value_length, SUBSTR(UTC_TIMESTAMP(), 5, 1) AS date_sep, SUBSTR(UTC_TIMESTAMP(), 14, 1) AS time_sep',
				"SELECT DATE('2008-01-02 13:29:17') AS value_date",
				"SELECT DATEDIFF('2008-01-09 13:29:17', '2008-01-02 00:00:00') AS day_delta",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 SECOND) AS shifted",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 2 WEEK) AS shifted",
				"SELECT DATE_SUB('2008-01-02 13:29:17', INTERVAL 1 MONTH) AS shifted",
				"SELECT DATE(DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 DAY)) AS nested_date",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 HOUR) AS shifted ORDER BY shifted",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_select_unseeded_rand_range_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT CAST(RAND() >= 0 AND RAND() < 1 AS SIGNED) AS rand_in_range' );
	}

	public function test_select_seeded_rand_literals_match_sqlite(): void {
		foreach (
			array(
				'SELECT RAND(0) AS r',
				'SELECT RAND(1) AS r',
				'SELECT RAND(5) AS r',
				'SELECT RAND(NULL) AS r',
				"SELECT RAND('5') AS r",
				"SELECT RAND('3.9') AS r",
				'SELECT RAND(3.9) AS r',
				'SELECT RAND(-1) AS r',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_select_seeded_rand_multi_row_sequence_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_rows (id INT)',
				'INSERT INTO seeded_rand_rows (id) VALUES (1), (2), (3)',
			)
		);

		$this->assertParityRows( 'SELECT id, RAND(3) AS r FROM seeded_rand_rows ORDER BY id' );
	}

	public function test_select_cast_convert_binary_expressions_match_sqlite(): void {
		foreach (
			array(
				"SELECT CAST('42' AS SIGNED) AS v",
				"SELECT CAST('-10' AS SIGNED) AS v",
				"SELECT CAST('3.5' AS DECIMAL(10,2)) AS v",
				'SELECT CAST(123 AS CHAR) AS v',
				"SELECT CAST('abc' AS BINARY) = 'abc' AS v",
				"SELECT CONVERT('abc', BINARY) = 'abc' AS v",
				"SELECT CONVERT('abc', CHAR) AS v",
				"SELECT CONVERT('-10', SIGNED) AS v",
				"SELECT CONVERT('Customer' USING utf8mb4) AS v",
				"SELECT CONVERT('Customer' USING utf8mb4) COLLATE utf8mb4_bin AS v",
				"SELECT BINARY 'abc' = 'abc' AS v",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_select_like_binary_expressions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE expr_strings (name VARCHAR(20))',
				"INSERT INTO expr_strings (name) VALUES ('abc'), ('ABC'), ('a_c'), ('a%c'), ('ábC')",
			)
		);

		foreach (
			array(
				"SELECT name FROM expr_strings WHERE BINARY name = 'ABC' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'A%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name NOT LIKE BINARY 'A%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'a\\_%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'a\\%%' ORDER BY name",
				'SELECT name FROM expr_strings ORDER BY BINARY name',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
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

	public function test_show_full_tables_sql_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE _tmp_table (id INT)',
				'CREATE TABLE _tmp_table_2 (id INT)',
				'CREATE TABLE shadow_show (id INT)',
				'CREATE TEMPORARY TABLE shadow_show (temp_id INT)',
				'CREATE TEMPORARY TABLE temp_only_show (id INT)',
			)
		);

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( "SHOW TABLES LIKE '_tmp_table'" );
		$this->assertParityRows( 'SHOW FULL TABLES' );
		$this->assertParityRows( "SHOW FULL TABLES LIKE '_tmp_table'" );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'shadow_show'" );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'temp_only_show'" );
	}

	public function test_view_lifecycle_limitations_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE view_source (id INT, name VARCHAR(20))',
			)
		);

		$this->assertParityErrorContains(
			'CREATE VIEW visible_view AS SELECT id, name FROM view_source',
			'createView'
		);
		$this->assertParityErrorContains(
			'CREATE OR REPLACE VIEW visible_view AS SELECT id FROM view_source',
			'createView'
		);
		$this->assertParityErrorContains( 'SHOW CREATE VIEW visible_view', 'CREATE' );
		$this->assertParityErrorContains( 'DROP VIEW missing_view', 'missing_view' );
		$this->runParitySetup( array( 'DROP VIEW IF EXISTS missing_view' ) );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'view_source'" );
		$this->assertParityRows(
			"SELECT TABLE_NAME, TABLE_TYPE
			FROM information_schema.tables
			WHERE TABLE_SCHEMA = 'wp' AND TABLE_NAME = 'view_source'"
		);
	}

	public function test_show_admin_metadata_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SHOW COLLATION' );
		$this->assertParityRows( "SHOW COLLATION LIKE 'utf8%'" );
		$this->assertParityRows( "SHOW COLLATION WHERE Collation = 'utf8_bin'" );
		$this->assertParityRows( 'SHOW COLLATION WHERE 0' );
		$this->assertParityRows( "SHOW COLLATION LIKE 'missing%'" );

		$this->assertParityRows( 'SHOW DATABASES' );
		$this->assertParityRows( 'SHOW DATABASES LIKE "w%"' );
		$this->assertParityRows( 'SHOW DATABASES WHERE `Database` = "wp"' );
		$this->assertParityRows( 'SHOW DATABASES WHERE `Database` = "information_schema"' );
		$this->assertParityRows( "SHOW DATABASES LIKE 'missing%'" );
		$this->assertParityRows( 'SHOW SCHEMAS' );
		$this->assertParityRows( "SHOW SCHEMAS LIKE 'wp'" );
		$this->assertParityRows( 'SHOW SCHEMAS WHERE `Database` = "wp"' );
		$this->assertParityRows( 'SHOW SCHEMAS WHERE 0' );

		$this->assertParityRows( 'SHOW GRANTS' );
		$this->assertParityRows( 'SHOW GRANTS FOR current_user()' );
		$this->assertParityRows( 'SHOW GRANTS FOR CURRENT_USER' );
		$this->assertParityRows( 'SHOW GRANTS FOR root@localhost' );
		$this->assertParityRows( "SHOW GRANTS FOR 'root'@'localhost'" );
		$this->assertParityRows( 'SHOW GRANTS FOR usera@localhost' );
		$this->assertParityRows( 'SHOW GRANTS FOR root' );

		$this->assertParityRows( 'SHOW VARIABLES' );
		$this->assertParityRows( "SHOW VARIABLES LIKE 'version'" );
		$this->assertParityRows( "SHOW VARIABLES WHERE Variable_name = 'version'" );
		$this->assertParityRows( 'SHOW VARIABLES WHERE 0' );
		$this->assertParityRows( 'SHOW GLOBAL VARIABLES' );
		$this->assertParityRows( 'SHOW SESSION VARIABLES' );
		$this->assertParityRows( 'SHOW LOCAL VARIABLES' );
		$this->assertParityRows( "SHOW GLOBAL VARIABLES LIKE 'version'" );
		$this->assertParityRows( "SHOW SESSION VARIABLES WHERE Variable_name = 'version'" );
		$this->assertParityRows( "SHOW LOCAL VARIABLES WHERE Variable_name = 'version'" );
	}

	public function test_show_admin_metadata_found_rows_match_sqlite(): void {
		foreach (
			array(
				'SHOW COLLATION',
				"SHOW COLLATION LIKE 'utf8_bin'",
				"SHOW COLLATION LIKE 'missing%'",
				'SHOW DATABASES',
				"SHOW DATABASES LIKE 'missing%'",
				"SHOW SCHEMAS LIKE 'wp'",
				'SHOW SCHEMAS WHERE 0',
				'SHOW GRANTS',
				'SHOW GRANTS FOR current_user()',
				'SHOW VARIABLES',
				'SHOW GLOBAL VARIABLES',
				'SHOW SESSION VARIABLES',
				'SHOW LOCAL VARIABLES',
				'SHOW VARIABLES WHERE 0',
			) as $sql
		) {
			$this->assertParityRows( $sql );
			$this->assertParityRows( 'SELECT FOUND_ROWS()' );
		}
	}

	public function test_transaction_sql_matches_sqlite(): void {
		$this->runParitySetup( array( 'CREATE TABLE tx_items (id INT)' ) );

		$this->runParitySetup(
			array(
				'BEGIN',
				'INSERT INTO tx_items (id) VALUES (1)',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );
		$this->runParitySetup( array( 'ROLLBACK' ) );
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'START TRANSACTION',
				'INSERT INTO tx_items (id) VALUES (2)',
				'COMMIT',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'BEGIN WORK',
				'INSERT INTO tx_items (id) VALUES (3)',
				'BEGIN',
				'INSERT INTO tx_items (id) VALUES (4)',
				'ROLLBACK WORK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'ROLLBACK',
				'COMMIT WORK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );
	}

	public function test_session_variable_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT @@autocommit, @@session.autocommit, @@big_tables, @@SESSION.big_tables' );

		$this->assertParityRowCount( 'SET SESSION autocommit = 1, big_tables = 0' );
		$this->assertParityRows( 'SELECT @@autocommit, @@session.autocommit, @@big_tables, @@session.big_tables' );

		foreach (
			array(
				'SET autocommit = ON, big_tables = OFF',
				'SET autocommit = on, big_tables = off',
				"SET autocommit = 'ON', big_tables = 'OFF'",
				"SET autocommit = 'on', big_tables = 'off'",
				'SET autocommit = TRUE, big_tables = FALSE',
				'SET autocommit = true, big_tables = false',
				'SET autocommit = 1, big_tables = 0',
			) as $sql
		) {
			$this->assertParityRowCount( $sql );
			$this->assertParityRows( 'SELECT @@autocommit, @@big_tables' );
		}

		$this->assertParityRowCount( 'SET autocommit = OFF' );
		$this->assertParityRows( 'SELECT @@autocommit' );
		$this->assertParityRowCount( 'SET big_tables = ON' );
		$this->assertParityRows( 'SELECT @@big_tables' );

		$this->assertParityRowCount( 'SET SESSION autocommit = 0' );
		$this->assertParityRowCount( 'SET @@session.big_tables = 1' );
		$this->assertParityRows(
			'SELECT @@autocommit, @@SESSION.autocommit, @@big_tables, @@session.big_tables'
		);

		$this->assertParityRowCount( 'SET autocommit = DEFAULT, big_tables = DEFAULT' );
		$this->assertParityRows( 'SELECT @@autocommit, @@big_tables' );
	}

	public function test_user_variable_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT @missing, @missing AS missing_alias, @missing implicit_alias' );

		$this->assertParityRowCount(
			"SET @my_var = 1, @name := 'Ada', @copy = @name, @mode = @@SQL_MODE, @nothing = NULL"
		);
		$this->assertParityRows(
			'SELECT @MY_VAR, @name AS name, @copy, @mode mode, @nothing AS nothing FROM DUAL'
		);

		$this->assertParityRowCount( 'SET @signed = -2, @decimal = +1.25, @flag = TRUE' );
		$this->assertParityRows( 'SELECT @signed, @decimal, @flag' );
	}

	public function test_dump_check_variable_backup_and_restore_sql_matches_sqlite(): void {
		$this->assertParityRowCount(
			'/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;'
		);
		$this->assertParityRows( 'SELECT @OLD_UNIQUE_CHECKS, @@UNIQUE_CHECKS' );

		$this->assertParityRowCount(
			'/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;'
		);
		$this->assertParityRows( 'SELECT @OLD_FOREIGN_KEY_CHECKS, @@FOREIGN_KEY_CHECKS' );

		$this->assertParityRowCount( '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;' );
		$this->assertParityRowCount( '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;' );
		$this->assertParityRows( 'SELECT @@UNIQUE_CHECKS, @@FOREIGN_KEY_CHECKS' );

		$this->assertParityRowCount(
			'SET @RESTORED_UNIQUE_CHECKS = 1, @RESTORED_FOREIGN_KEY_CHECKS = "0"'
		);
		$this->assertParityRowCount( 'SET UNIQUE_CHECKS=@RESTORED_UNIQUE_CHECKS' );
		$this->assertParityRowCount( 'SET FOREIGN_KEY_CHECKS=@RESTORED_FOREIGN_KEY_CHECKS' );
		$this->assertParityRows( 'SELECT @@UNIQUE_CHECKS, @@FOREIGN_KEY_CHECKS' );
	}

	public function test_sql_mode_bootstrap_sql_matches_sqlite(): void {
		$this->assertParityRowCount( 'SET NAMES utf8mb4' );
		$this->assertParityRowCount( 'SET CHARSET utf8mb4' );
		$this->assertParityRowCount( 'SET CHARACTER SET utf8mb4' );

		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount( 'SET NAMES utf8mb4, autocommit = 0' );
		$this->assertParityRows( 'SELECT @@autocommit' );

		$this->assertParityRowCount( "SET CHARACTER SET utf8mb4, sql_mode = 'NO_ZERO_DATE'" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount(
			"SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"
		);
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );
	}

	public function test_builtin_system_variables_match_sqlite(): void {
		$this->assertParityRows( 'SELECT @@version, @@version_comment' );
	}

	public function test_lock_unlock_sql_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE lock_items (id INT)',
				'CREATE TEMPORARY TABLE lock_temp (id INT)',
			)
		);

		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES wp.lock_items WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_temp READ, lock_items WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items AS li READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items li READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items READ LOCAL' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items AS li READ LOCAL' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items li LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_temp AS lt READ LOCAL, lock_items li LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );

		$this->runParitySetup(
			array(
				'BEGIN',
				'INSERT INTO lock_items (id) VALUES (1)',
				'LOCK TABLES lock_items WRITE',
				'INSERT INTO lock_items (id) VALUES (2)',
				'UNLOCK TABLES',
				'ROLLBACK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM lock_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'LOCK TABLES lock_items WRITE',
				'BEGIN',
				'INSERT INTO lock_items (id) VALUES (3)',
				'COMMIT',
				'UNLOCK TABLES',
			)
		);
		$this->assertParityRows( 'SELECT id FROM lock_items ORDER BY id' );

		$this->assertParityErrorContains( 'LOCK TABLES missing_lock_item READ', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES lock_items READ, missing_lock_item WRITE', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES lock_items AS li READ LOCAL, missing_lock_item missing LOW_PRIORITY WRITE', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES information_schema.tables READ', "to database 'information_schema'" );
	}

	public function test_update_delete_alias_order_limit_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'b', 1), (2, 'a', 2), (3, 'c', 3)",
			)
		);

		$this->assertParityRowCount( 'UPDATE items SET hits = 9 ORDER BY name LIMIT 1' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "UPDATE items AS i SET i.hits = 7 WHERE i.name = 'b' LIMIT 1" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'UPDATE wp.items SET hits = 6 WHERE id = 3' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'UPDATE items SET hits = 5 LIMIT 0' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "DELETE FROM items AS i WHERE i.name = 'b' LIMIT 1" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'DELETE FROM wp.items ORDER BY name LIMIT 1' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'DELETE FROM items LIMIT 0' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_sql_calc_found_rows_and_found_rows_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_found_rows_users (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					user_login VARCHAR(60) NOT NULL DEFAULT '',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_found_rows_users (user_login) VALUES
					('ada'),
					('grace'),
					('katherine')",
			)
		);

		$this->assertParityRows(
			'SELECT SQL_CALC_FOUND_ROWS ID, user_login FROM wp_found_rows_users ORDER BY ID LIMIT 2'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_found_rows_state_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_found_rows_state (id INT, label VARCHAR(20))',
				"INSERT INTO wp_found_rows_state VALUES (1, 'one'), (2, 'two'), (3, 'three')",
			)
		);

		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'SELECT id, label FROM wp_found_rows_state ORDER BY id' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRowCount( "UPDATE wp_found_rows_state SET label = 'updated' WHERE id = 1" );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id, label FROM wp_found_rows_state ORDER BY id' );
		$this->runParitySetup( array( 'CREATE TABLE wp_found_rows_state_extra (id INT)' ) );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'DESCRIBE wp_found_rows_state' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_failed_selects_reset_found_rows_state_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_found_rows_reset (id INT)',
				'INSERT INTO wp_found_rows_reset VALUES (1), (2), (3)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityErrorContains( 'SELECT * FROM missing_found_rows_reset', 'missing_found_rows_reset' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityErrorContains(
			'SELECT SQL_CALC_FOUND_ROWS * FROM missing_found_rows_reset LIMIT 1',
			'missing_found_rows_reset'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_select_index_hints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_hint_posts (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					post_title VARCHAR(200) NOT NULL DEFAULT '',
					PRIMARY KEY (ID),
					KEY post_status (post_status)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_hint_posts (post_status, post_title) VALUES
					('publish', 'first'),
					('draft', 'second'),
					('publish', 'third')",
			)
		);

		$this->assertParityRows(
			"SELECT post_title
			FROM wp_hint_posts FORCE INDEX (PRIMARY, post_status)
			WHERE post_status = 'publish'
			ORDER BY ID"
		);
		$this->assertParityRows(
			'SELECT post_status, COUNT(*) AS total
			FROM wp_hint_posts USE KEY FOR GROUP BY (post_status)
			GROUP BY post_status
			ORDER BY post_status'
		);
		$this->assertParityRows(
			'SELECT post_title
			FROM wp_hint_posts IGNORE INDEX FOR ORDER BY (post_status)
			ORDER BY ID DESC
			LIMIT 1'
		);
	}

	public function test_row_locking_clauses_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_lock_items (name VARCHAR(255), value VARCHAR(255))',
				"INSERT INTO wp_lock_items (name, value) VALUES ('test_lock', '123')",
			)
		);

		foreach (
			array(
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR SHARE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' LOCK IN SHARE MODE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE SKIP LOCKED",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE NOWAIT",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_order_by_field_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_field_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value VARCHAR(191) NOT NULL DEFAULT '',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_field_options (option_name, option_value) VALUES
					('User 0000019', 'second'),
					('User 0000020', 'third'),
					('User 0000018', 'first')",
			)
		);

		$this->assertParityRows(
			"SELECT FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020') AS sorting_order
			FROM wp_field_options
			ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
		);
		$this->assertParityRows(
			"SELECT option_value
			FROM wp_field_options
			ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
		);
		$this->assertParityRows(
			"SELECT FIELD('b', 'A', 'B') AS case_match,
			FIELD(NULL, 'A', 'B') AS null_match,
			FIELD('z', 'A', 'B') AS no_match"
		);
	}

	public function test_joined_update_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE posts (id INT, status VARCHAR(20), score INT)',
				'CREATE TABLE post_updates (post_id INT, new_status VARCHAR(20), bump INT, flag VARCHAR(20))',
				"INSERT INTO posts VALUES (1, 'draft', 0), (2, 'draft', 0), (3, 'publish', 5)",
				"INSERT INTO post_updates VALUES
					(1, 'publish', 10, 'apply'),
					(2, 'private', 20, 'skip'),
					(3, 'archive', 30, 'apply')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE posts p
			JOIN post_updates u ON u.post_id = p.id
			SET p.status = u.new_status, p.score = p.score + u.bump
			WHERE u.flag = 'apply'"
		);
		$this->assertParityRows( 'SELECT id, status, score FROM posts ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE posts p, post_updates u
			SET p.status = 'queued'
			WHERE p.id = u.post_id AND u.flag = 'skip'"
		);
		$this->assertParityRows( 'SELECT id, status, score FROM posts ORDER BY id' );
	}

	public function test_joined_update_non_first_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'x'), (2, 'y'), (3, 'q')",
				'CREATE TABLE tree (id INT, parent_id INT, label VARCHAR(20))',
				"INSERT INTO tree VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 1, 'sibling')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET b.note = 'z'
			WHERE a.id IN (1, 3)"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE t1 a, t2 b
			SET b.note = 'comma'
			WHERE a.id = b.id AND a.id = 2"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE tree parent JOIN tree child ON child.parent_id = parent.id
			SET child.label = 'claimed'
			WHERE parent.id = 1 AND child.id = 2"
		);
		$this->assertParityRows( 'SELECT id, label FROM tree ORDER BY id' );
	}

	public function test_multi_target_joined_update_rejection_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b')",
				"INSERT INTO t2 VALUES (1, 'x'), (3, 'z')",
			)
		);

		$this->assertParityErrorContains(
			"UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET a.note = 'target', b.note = 'source'",
			'UPDATE statement modifying multiple tables'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityErrorContains(
			"UPDATE t1 a, t2 b
			SET a.note = 'target', b.note = 'source'
			WHERE a.id = b.id",
			'UPDATE statement modifying multiple tables'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );
	}

	public function test_joined_update_unqualified_unique_unaliased_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

			$this->assertParityRowCount(
				'UPDATE t1 JOIN t2 ON t1.id = t2.id
				SET only_t1 = 99
				WHERE t2.flag = 1'
			);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_update_unsupported_join_forms_document_current_duckdb_gap(): void {
		foreach (
			array(
				array(
					'sql'              => "UPDATE t1 a LEFT JOIN t2 b ON a.id = b.id SET a.note = 'left'",
					'duckdb_message'   => 'Only comma joins and INNER JOIN ... ON are supported',
					'sqlite_row_count' => 2,
					'sqlite_rows'      => array(
						array(
							'id'      => '1',
							'note'    => 'left',
							'only_t1' => '10',
						),
						array(
							'id'      => '2',
							'note'    => 'a2',
							'only_t1' => '20',
						),
						array(
							'id'      => '3',
							'note'    => 'left',
							'only_t1' => '30',
						),
						array(
							'id'      => '4',
							'note'    => 'a4',
							'only_t1' => '40',
						),
					),
				),
				array(
					'sql'              => "UPDATE t1 a RIGHT JOIN t2 b ON a.id = b.id SET a.note = 'right'",
					'duckdb_message'   => 'Only comma joins and INNER JOIN ... ON are supported',
					'sqlite_row_count' => 2,
					'sqlite_rows'      => array(
						array(
							'id'      => '1',
							'note'    => 'right',
							'only_t1' => '10',
						),
						array(
							'id'      => '2',
							'note'    => 'a2',
							'only_t1' => '20',
						),
						array(
							'id'      => '3',
							'note'    => 'right',
							'only_t1' => '30',
						),
						array(
							'id'      => '4',
							'note'    => 'a4',
							'only_t1' => '40',
						),
					),
				),
				array(
					'sql'              => "UPDATE t1 a JOIN t2 b USING (id) SET a.note = 'using'",
					'duckdb_message'   => 'JOIN ... USING is not supported',
					'sqlite_row_count' => 4,
					'sqlite_rows'      => array(
						array(
							'id'      => '1',
							'note'    => 'using',
							'only_t1' => '10',
						),
						array(
							'id'      => '2',
							'note'    => 'using',
							'only_t1' => '20',
						),
						array(
							'id'      => '3',
							'note'    => 'using',
							'only_t1' => '30',
						),
						array(
							'id'      => '4',
							'note'    => 'using',
							'only_t1' => '40',
						),
					),
				),
				array(
					'sql'              => "UPDATE t1 a NATURAL JOIN t2 b SET a.note = 'natural'",
					'duckdb_message'   => 'Only comma joins and INNER JOIN ... ON are supported',
					'sqlite_row_count' => 4,
					'sqlite_rows'      => array(
						array(
							'id'      => '1',
							'note'    => 'natural',
							'only_t1' => '10',
						),
						array(
							'id'      => '2',
							'note'    => 'natural',
							'only_t1' => '20',
						),
						array(
							'id'      => '3',
							'note'    => 'natural',
							'only_t1' => '30',
						),
						array(
							'id'      => '4',
							'note'    => 'natural',
							'only_t1' => '40',
						),
					),
				),
			) as $case
		) {
			$drivers = $this->createJoinedUpdateGapDrivers();

			$this->assertSame(
				$case['sqlite_row_count'],
				(int) $drivers['sqlite']->query( $case['sql'], PDO::FETCH_ASSOC ),
				'SQLite row count changed for SQL: ' . $case['sql']
			);
			$this->assertDuckDBGapQueryRejected( $drivers['duckdb'], $case['sql'], $case['duckdb_message'] );

			$this->assertSame(
				$case['sqlite_rows'],
				$drivers['sqlite']->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id', PDO::FETCH_ASSOC ),
				'SQLite rows changed for SQL: ' . $case['sql']
			);
			$this->assertSame(
				$this->joinedUpdateGapInitialDuckDBRows(),
				$drivers['duckdb']->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'DuckDB rejected joined UPDATE mutated t1 for SQL: ' . $case['sql']
			);
			$this->assertSame(
				$this->joinedUpdateGapInitialDuckDBSourceRows(),
				$drivers['duckdb']->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'DuckDB rejected joined UPDATE mutated t2 for SQL: ' . $case['sql']
			);
		}
	}

	public function test_joined_update_unqualified_unique_aliased_target_rejects_duckdb_gap(): void {
		$drivers = $this->createJoinedUpdateGapDrivers();
		$sql     = 'UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET only_t1 = 99
			WHERE b.flag = 1';

		$this->assertSame( 4, (int) $drivers['sqlite']->query( $sql, PDO::FETCH_ASSOC ) );
		$this->assertDuckDBGapQueryRejected(
			$drivers['duckdb'],
			$sql,
			"Unqualified UPDATE target column 'only_t1' is not supported for aliased joined UPDATE targets"
		);

		$this->assertSame(
			array(
				array(
					'id'      => '1',
					'note'    => 'a1',
					'only_t1' => '99',
				),
				array(
					'id'      => '2',
					'note'    => 'a2',
					'only_t1' => '99',
				),
				array(
					'id'      => '3',
					'note'    => 'a3',
					'only_t1' => '99',
				),
				array(
					'id'      => '4',
					'note'    => 'a4',
					'only_t1' => '99',
				),
			),
			$drivers['sqlite']->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id', PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			$this->joinedUpdateGapInitialDuckDBRows(),
			$drivers['duckdb']->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
			'DuckDB rejected joined UPDATE mutated t1.'
		);
		$this->assertSame(
			$this->joinedUpdateGapInitialDuckDBSourceRows(),
			$drivers['duckdb']->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
			'DuckDB rejected joined UPDATE mutated t2.'
		);
	}

	public function test_joined_update_derived_table_claim_query_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_actionscheduler_actions (
					action_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					status VARCHAR(20) NOT NULL,
					scheduled_date_gmt DATETIME NULL,
					priority TINYINT UNSIGNED NOT NULL DEFAULT '10',
					attempts INT(11) NOT NULL DEFAULT '0',
					claim_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					last_attempt_gmt DATETIME NULL,
					last_attempt_local DATETIME NULL,
					PRIMARY KEY (action_id)
				)",
				"INSERT INTO wp_actionscheduler_actions
					(action_id, status, scheduled_date_gmt, priority, attempts, claim_id)
				VALUES
					(1, 'pending', '2025-09-03 12:00:00', 10, 0, 0),
					(2, 'pending', '2025-09-03 12:10:00', 5, 0, 0)",
			)
		);

		$this->runParitySetup(
			array(
				"UPDATE wp_actionscheduler_actions t1
			JOIN (
				SELECT action_id
				FROM wp_actionscheduler_actions
				WHERE claim_id = 0
				AND scheduled_date_gmt <= '2025-09-03 12:23:55'
				AND status = 'pending'
				ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC
				LIMIT 2
				FOR UPDATE
			) t2 ON t1.action_id = t2.action_id
			SET claim_id = 37,
				last_attempt_gmt = '2025-09-03 12:23:55',
				last_attempt_local = '2025-09-03 12:23:55'",
			)
		);
		$this->assertParityRows(
			'SELECT action_id, claim_id, last_attempt_gmt
			FROM wp_actionscheduler_actions
			ORDER BY action_id'
		);
	}

	public function test_multi_table_delete_transient_cleanup_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES
					('_transient_tag4', 'tag4', 'no'),
					('_transient_timeout_tag4', '1', 'no'),
					('_transient_tag5', 'tag5', 'no'),
					('_transient_timeout_tag5', '9999999999', 'no'),
					('_site_transient_tag1', 'tag1', 'no'),
					('_site_transient_timeout_tag1', '1', 'no'),
					('rss_1', 'rss', 'yes')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a, b FROM wp_options a, wp_options b
			WHERE a.option_name LIKE '\_transient\_%'
			AND a.option_name NOT LIKE '\_transient\_timeout_%'
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < UNIX_TIMESTAMP()"
		);
		$this->assertParityRows( 'SELECT option_name FROM wp_options ORDER BY option_id' );
	}

	public function test_multi_table_delete_using_and_single_target_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'x'), (3, 'z'), (4, 'other')",
			)
		);

		$this->assertParityRowCount( 'DELETE FROM a, b USING t1 a, t2 b WHERE a.id = b.id AND a.id = 1' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount( 'DELETE a FROM t1 a, t2 b WHERE a.id = b.id' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );
	}

	public function test_joined_delete_using_columns_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM t1 a
			JOIN t2 b USING (id)
			WHERE b.flag = 'drop'"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, flag FROM t2 ORDER BY id' );
	}

	public function test_single_target_joined_delete_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f')",
				"INSERT INTO t2 VALUES
					(10, 1, 'drop', 'x'),
					(11, 1, 'drop', 'duplicate'),
					(12, 2, 'keep', 'y'),
					(13, 3, 'drop', 'z'),
					(14, 4, 'drop', 'w'),
					(15, 5, 'source', 's'),
					(16, 6, 'source', 'q')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE FROM a USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE a.id = 5 AND b.flag = 'source'"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE t1 FROM t1
			JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount( 'DELETE a FROM t1 a WHERE a.id = 6' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE child FROM t1 parent
			JOIN t1 child ON child.id = 5
			WHERE parent.id = 2'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );
	}

	public function test_multi_target_joined_delete_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f')",
				"INSERT INTO t2 VALUES
					(10, 1, 'drop', 'x'),
					(11, 1, 'drop', 'duplicate'),
					(12, 2, 'keep', 'y'),
					(13, 3, 'drop', 'z'),
					(14, 4, 'drop', 'w'),
					(15, 5, 'same-table', 's'),
					(16, 6, 'keep', 'q')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a, b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE FROM a, b USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE t1, t2 FROM t1
			INNER JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE parent, child FROM t1 parent
			JOIN t1 child ON child.id = 5
			WHERE parent.id = 2'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );
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

	public function test_insert_select_and_replace_select_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE source_items (id INTEGER, name VARCHAR(100))',
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100))',
				"INSERT INTO source_items VALUES (1, 'first'), (2, 'second')",
			)
		);

		$this->assertParityRowCount( 'INSERT INTO items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertParityRowCount( 'INSERT INTO items SELECT id, name FROM source_items WHERE id = 2' );
		$this->assertParityRowCount( 'INSERT IGNORE items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertParityRowCount( "INSERT items (id, name) SELECT 3, 'third' FROM DUAL WHERE (SELECT NULL FROM DUAL) IS NULL" );
		$this->assertParityRowCount( "REPLACE INTO items (id, name) SELECT 1, 'replaced' FROM DUAL" );
		$this->assertParityRows( 'SELECT id, name FROM items ORDER BY id' );
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

	public function test_on_duplicate_key_update_qualified_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t (
					id INTEGER PRIMARY KEY,
					d VARCHAR(100) NOT NULL DEFAULT \'\'
				)',
				"INSERT INTO t (id, d) VALUES (1, 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO t (id, d) VALUES (1, 'new')
			ON DUPLICATE KEY UPDATE t.d = VALUES(d)"
		);
		$this->assertParityRows( 'SELECT id, d FROM t ORDER BY id' );
	}

	public function test_temporal_insert_values_and_set_writes_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_writes' );

		$this->assertParityRowCount(
			"INSERT INTO temporal_writes (id, d, tm, dt, ts, payload) VALUES
				(1, '2025-10-23', '18:30:00', '2025-10-23 18:30:00', '2025-10-23 18:30:00', 'canonical'),
				(2, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-23', '2025-10-23', 'normalized'),
				(3, NULL, NULL, NULL, NULL, 'nullable')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_writes SET
				id = 4,
				d = '2025-11-01 01:02:03.123456',
				tm = '18:30:00.123456',
				dt = '2025-11-01',
				ts = '2025-11-01 01:02:03.123456',
				payload = 'insert-set'"
		);
		$this->assertParityRowCount(
			"INSERT IGNORE INTO temporal_writes (id, d, tm, dt, ts, payload) VALUES
				(5, '2025-12-01 05:06:07.123456', '18:30:00.123456', '2025-12-01', '2025-12-01 05:06:07.123456', 'insert-ignore')"
		);

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_writes' ) );
	}

	public function test_temporal_insert_select_explicit_columns_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_explicit' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_explicit_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_explicit_source VALUES
					(1, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-24', '2025-10-24 01:02:03.123456', 'explicit')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_explicit (payload, ts, dt, tm, d, id)
			SELECT payload, ts_text, dt_text, tm_text, d_text, id
			FROM temporal_insert_select_explicit_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_explicit' ) );
	}

	public function test_temporal_insert_select_implicit_columns_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_implicit' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_implicit_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_implicit_source VALUES
					(1, '2025-11-01 05:06:07.123456', '18:31:32.654321', '2025-11-02', '2025-11-03 04:05:06.654321', 'implicit')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_implicit
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_insert_select_implicit_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_implicit' ) );
	}

	public function test_temporal_insert_select_strict_errors_leave_rows(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_strict' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_insert_select_strict (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'stable')",
				'CREATE TABLE temporal_insert_select_strict_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_strict_source VALUES
					(2, 'bad', '2025-01-02', 'plain'),
					(3, '2025-01-03', 'bad', 'ignore')",
			)
		);

		$stable_selects = array(
			$this->temporal_select_sql( 'temporal_insert_select_strict' ),
			'SELECT id, d_text, dt_text, payload FROM temporal_insert_select_strict_source ORDER BY id',
		);

		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_insert_select_strict (id, d, dt, payload)
			SELECT id, d_text, dt_text, payload
			FROM temporal_insert_select_strict_source
			WHERE payload = 'plain'",
			"Incorrect date value: 'bad'",
			$stable_selects
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT IGNORE INTO temporal_insert_select_strict (id, d, dt, payload)
			SELECT id, d_text, dt_text, payload
			FROM temporal_insert_select_strict_source
			WHERE payload = 'ignore'",
			"Incorrect datetime value: 'bad'",
			$stable_selects
		);
	}

	public function test_temporal_insert_select_non_strict_defaults_and_nulls_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_insert_select_non_strict', 'NOT NULL' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_non_strict_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_non_strict_source VALUES
					(1, 'bad', 'bad', 'bad', 'invalid'),
					(2, NULL, NULL, NULL, 'selected-nulls')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_non_strict (id, d, dt, ts, payload)
			SELECT id, d_text, dt_text, ts_text, payload
			FROM temporal_insert_select_non_strict_source
			ORDER BY id'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_non_strict' ) );
	}

	public function test_temporal_insert_ignore_select_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_ignore_select' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_insert_ignore_select (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'stable')",
				'CREATE TABLE temporal_insert_ignore_select_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_ignore_select_source VALUES
					(1, '2025-02-01 01:02:03', '18:31:00.123456', '2025-02-01', '2025-02-01 01:02:03.123456', 'ignored'),
					(2, '2025-03-01 01:02:03', '18:32:00.123456', '2025-03-01', '2025-03-01 01:02:03.123456', 'inserted')",
			)
		);

		$this->assertParityRowCount(
			'INSERT IGNORE INTO temporal_insert_ignore_select (id, d, tm, dt, ts, payload)
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_insert_ignore_select_source
			ORDER BY id'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_ignore_select' ) );
	}

	public function test_temporal_replace_select_native_conflict_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_native (
					id INT,
					d DATE UNIQUE,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_native (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'old')",
				'CREATE TABLE temporal_replace_select_native_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_native_source VALUES
					(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'new')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_native (id, d, dt, ts, payload)
			SELECT id, d_text, dt_text, ts_text, payload
			FROM temporal_replace_select_native_source'
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_replace_select_native ORDER BY id' );
	}

	public function test_temporal_replace_select_non_strict_selected_nulls_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_replace_select_non_strict_nulls', 'NOT NULL' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_replace_select_non_strict_nulls (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-10-23', '18:30:00', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'old')",
				'CREATE TABLE temporal_replace_select_non_strict_nulls_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_non_strict_nulls_source VALUES
					(1, NULL, NULL, NULL, NULL, 'selected-nulls')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_non_strict_nulls (id, d, tm, dt, ts, payload)
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_replace_select_non_strict_nulls_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_replace_select_non_strict_nulls' ) );
	}

	public function test_temporal_replace_select_strict_error_preserves_target(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_error (
					id INT PRIMARY KEY,
					d DATE,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_error (id, d, payload) VALUES
					(1, '2025-10-23', 'stable')",
				'CREATE TABLE temporal_replace_select_error_source (
					id INT,
					d_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_error_source VALUES
					(1, 'bad', 'stable')",
			)
		);

		$this->assert_temporal_error_leaves_rows(
			'REPLACE INTO temporal_replace_select_error (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_replace_select_error_source',
			"Incorrect date value: 'bad'",
			array(
				'SELECT id, d, payload FROM temporal_replace_select_error ORDER BY id',
				'SELECT id, d_text, payload FROM temporal_replace_select_error_source ORDER BY id',
			)
		);
	}

	public function test_replace_select_unsafe_conflict_shapes_are_rejected_without_mutation(): void {
		$cases = array(
			array(
				'setup'  => array(
					'CREATE TABLE replace_select_temporal_multi_unique (
						id INT PRIMARY KEY,
						name VARCHAR(20) UNIQUE,
						d DATE NULL,
						payload VARCHAR(20)
					)',
					"INSERT INTO replace_select_temporal_multi_unique VALUES
						(1, 'one', '2025-01-01', 'old-one'),
						(2, 'two', '2025-01-02', 'old-two')",
					'CREATE TABLE replace_select_temporal_multi_unique_source (
						id INT,
						name VARCHAR(20),
						d_text VARCHAR(40),
						payload VARCHAR(20)
					)',
					"INSERT INTO replace_select_temporal_multi_unique_source VALUES
						(1, 'two', '2025-03-03', 'incoming')",
				),
				'sql'    => 'REPLACE INTO replace_select_temporal_multi_unique (id, name, d, payload)
					SELECT id, name, d_text, payload
					FROM replace_select_temporal_multi_unique_source',
				'select' => 'SELECT id, name, d, payload FROM replace_select_temporal_multi_unique ORDER BY id',
				'rows'   => array(
					array(
						'id'      => 1,
						'name'    => 'one',
						'd'       => '2025-01-01',
						'payload' => 'old-one',
					),
					array(
						'id'      => 2,
						'name'    => 'two',
						'd'       => '2025-01-02',
						'payload' => 'old-two',
					),
				),
			),
			array(
				'setup'  => array(
					'CREATE TABLE replace_select_non_temporal_multi_unique (
						id INT PRIMARY KEY,
						email VARCHAR(40) UNIQUE,
						payload VARCHAR(20)
					)',
					"INSERT INTO replace_select_non_temporal_multi_unique VALUES
						(1, 'one@example.test', 'old-one'),
						(2, 'two@example.test', 'old-two')",
					'CREATE TABLE replace_select_non_temporal_multi_unique_source (
						id INT,
						email VARCHAR(40),
						payload VARCHAR(20)
					)',
					"INSERT INTO replace_select_non_temporal_multi_unique_source VALUES
						(1, 'two@example.test', 'incoming')",
				),
				'sql'    => 'REPLACE INTO replace_select_non_temporal_multi_unique (id, email, payload)
					SELECT id, email, payload
					FROM replace_select_non_temporal_multi_unique_source',
				'select' => 'SELECT id, email, payload FROM replace_select_non_temporal_multi_unique ORDER BY id',
				'rows'   => array(
					array(
						'id'      => 1,
						'email'   => 'one@example.test',
						'payload' => 'old-one',
					),
					array(
						'id'      => 2,
						'email'   => 'two@example.test',
						'payload' => 'old-two',
					),
				),
			),
			array(
				'setup'  => array(
					"CREATE TABLE replace_select_ci_unique (
						id INT,
						name VARCHAR(20) NOT NULL DEFAULT '',
						payload VARCHAR(20),
						UNIQUE KEY name (name)
					) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
					"INSERT INTO replace_select_ci_unique VALUES (1, 'first', 'old')",
					'CREATE TABLE replace_select_ci_unique_source (
						id INT,
						name VARCHAR(20),
						payload VARCHAR(20)
					)',
					"INSERT INTO replace_select_ci_unique_source VALUES (2, 'FIRST', 'incoming')",
				),
				'sql'    => 'REPLACE INTO replace_select_ci_unique (id, name, payload)
					SELECT id, name, payload
					FROM replace_select_ci_unique_source',
				'select' => 'SELECT id, name, payload FROM replace_select_ci_unique ORDER BY id',
				'rows'   => array(
					array(
						'id'      => 1,
						'name'    => 'first',
						'payload' => 'old',
					),
				),
			),
		);

		foreach ( $cases as $case ) {
			$driver = new WP_DuckDB_Driver(
				array(
					'path'     => ':memory:',
					'database' => 'wp',
				)
			);

			foreach ( $case['setup'] as $query ) {
				$driver->query( $query );
			}

			$this->assert_duckdb_error_contains(
				$driver,
				$case['sql'],
				'Manual conflict handling for multiple unique keys or case-insensitive unique keys is not yet supported'
			);
			$this->assertSame( $case['rows'], $driver->query( $case['select'] )->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_no_select_write_stage_tables( $driver );
		}
	}

	public function test_temporal_select_write_stage_tables_are_cleaned_up(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'CREATE TABLE temporal_stage_cleanup (id INT PRIMARY KEY, d DATE NULL, payload VARCHAR(20))' );
		$driver->query( 'CREATE TABLE temporal_stage_cleanup_source (id INT, d_text VARCHAR(40), payload VARCHAR(20))' );
		$driver->query(
			"INSERT INTO temporal_stage_cleanup_source VALUES
				(1, '2025-01-01', 'valid'),
				(2, 'bad', 'invalid')"
		);

		$driver->query(
			"INSERT INTO temporal_stage_cleanup (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_stage_cleanup_source
			WHERE payload = 'valid'"
		);
		$this->assert_no_select_write_stage_tables( $driver );

		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_stage_cleanup (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_stage_cleanup_source
			WHERE payload = 'invalid'",
			"Incorrect date value: 'bad'"
		);
		$this->assert_no_select_write_stage_tables( $driver );

		$driver->query(
			"REPLACE INTO temporal_stage_cleanup (id, d, payload)
			SELECT 1, '2025-02-02', 'replaced'"
		);
		$this->assert_no_select_write_stage_tables( $driver );
	}

	public function test_temporal_strict_write_errors_match_sqlite(): void {
		$this->create_temporal_write_table(
			'temporal_strict',
			'NULL',
			array(
				'UNIQUE KEY payload_unique (payload)',
			)
		);
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_strict (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'original')",
				'CREATE TABLE temporal_strict_source (id INT, dt_text VARCHAR(40), ts_text VARCHAR(40))',
				"INSERT INTO temporal_strict_source VALUES (1, 'bad', 'bad')",
			)
		);

		$stable_selects = array(
			$this->temporal_select_sql( 'temporal_strict' ),
			'SELECT id, dt_text, ts_text FROM temporal_strict_source ORDER BY id',
		);

		foreach (
			array(
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'insert-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, TRUE, 'insert-true-date')",
					'needle' => "Incorrect date value: '1'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, dt, payload) VALUES (2, 0, 'insert-zero-datetime')",
					'needle' => "Incorrect datetime value: '0'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, ts, payload) VALUES (2, TRUE, 'insert-true-timestamp')",
					'needle' => "Incorrect timestamp value: '1'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, '0000-00-00', 'insert-zero-date')",
					'needle' => "Incorrect date value: '0000-00-00'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, dt, payload) VALUES (2, '0000-00-00 00:00:00', 'insert-zero-datetime')",
					'needle' => "Incorrect datetime value: '0000-00-00 00:00:00'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, ts, payload) VALUES (2, '2020-01-00 00:00:00', 'insert-zero-in-timestamp')",
					'needle' => "Incorrect timestamp value: '2020-01-00 00:00:00'",
				),
				array(
					'sql'    => "INSERT IGNORE INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'ignore-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict SET id = 2, dt = 'bad', payload = 'set-bad-datetime'",
					'needle' => "Incorrect datetime value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict SET id = 2, d = '0000-00-00', payload = 'set-zero-date'",
					'needle' => "Incorrect date value: '0000-00-00'",
				),
				array(
					'sql'    => "REPLACE INTO temporal_strict (id, d, payload) VALUES (1, 'bad', 'replace-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "UPDATE temporal_strict SET ts = 'bad' WHERE id = 1",
					'needle' => "Incorrect timestamp value: 'bad'",
				),
				array(
					'sql'    => 'UPDATE temporal_strict t
						JOIN temporal_strict_source s ON s.id = t.id
						SET t.dt = s.dt_text
						WHERE t.id = 1',
					'needle' => "Incorrect datetime value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'odku-insert')
						ON DUPLICATE KEY UPDATE payload = 'unexpected'",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, dt, payload) VALUES (2, '2025-01-02', '2025-01-02', 'original')
						ON DUPLICATE KEY UPDATE dt = 'bad'",
					'needle' => "Incorrect datetime value: 'bad'",
				),
			) as $case
		) {
			$this->assert_temporal_error_leaves_rows( $case['sql'], $case['needle'], $stable_selects );
		}
	}

	public function test_temporal_zero_date_sql_modes_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_modes_default' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_modes_default (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'stable')",
			)
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_modes_default (id, d, payload) VALUES (2, '0000-00-00', 'default-zero-date')",
			"Incorrect date value: '0000-00-00'",
			array( $this->temporal_select_sql( 'temporal_modes_default' ) )
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_modes_default SET dt = '2020-00-15 00:00:00' WHERE id = 1",
			"Incorrect datetime value: '2020-00-15 00:00:00'",
			array( $this->temporal_select_sql( 'temporal_modes_default' ) )
		);

		$this->assertParityRowCount( "SET sql_mode = 'STRICT_TRANS_TABLES'" );
		$this->create_temporal_write_table( 'temporal_modes_strict' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_strict (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '0000-00-00 00:00:00', '2020-01-00 00:00:00', 'strict-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'strict-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_strict
			SET d = '2020-00-15', dt = '2020-01-00 00:00:00', ts = '0000-00-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_strict' ) );

		$this->assertParityRowCount( "SET sql_mode = 'NO_ZERO_DATE'" );
		$this->create_temporal_write_table( 'temporal_modes_no_zero_date' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_no_zero_date (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '0000-00-00 00:00:00', '0000-00-00 00:00:00', 'no-zero-date-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'no-zero-date-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_no_zero_date
			SET d = '0000-00-00', dt = '0000-00-00 00:00:00', ts = '0000-00-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_no_zero_date' ) );

		$this->assertParityRowCount( "SET sql_mode = 'NO_ZERO_IN_DATE'" );
		$this->create_temporal_write_table( 'temporal_modes_no_zero_in_date' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_no_zero_in_date (id, d, tm, dt, ts, payload) VALUES
				(1, '2020-00-15', '18:30:00', '2020-01-00 00:00:00', '2020-00-15 00:00:00', 'no-zero-in-date-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'no-zero-in-date-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_no_zero_in_date
			SET d = '2020-01-00', dt = '2020-00-15 00:00:00', ts = '2020-01-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_no_zero_in_date' ) );

		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_modes_empty' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_empty (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '2020-00-15 00:00:00', '2020-01-00 00:00:00', 'empty-mode-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'empty-mode-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_empty
			SET d = '2020-00-15', dt = '0000-00-00 00:00:00', ts = '2020-01-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_empty' ) );
	}

	public function test_temporal_comma_space_sql_mode_rejects_zero_dates_in_duckdb(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES, NO_ZERO_DATE, NO_ZERO_IN_DATE'" );
		$row = $driver->query( 'SELECT @@SESSION.sql_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE', $row['@@SESSION.sql_mode'] );

		$this->create_duckdb_temporal_mode_table( $driver, 'temporal_comma_space_modes' );
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_comma_space_modes (id, d, payload) VALUES (1, '0000-00-00', 'zero-date')",
			"Incorrect date value: '0000-00-00'"
		);
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_comma_space_modes (id, dt, payload) VALUES (1, '2020-00-15 00:00:00', 'zero-in-date')",
			"Incorrect datetime value: '2020-00-15 00:00:00'"
		);

		$row = $driver->query( 'SELECT COUNT(*) AS row_count FROM temporal_comma_space_modes' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $row['row_count'] );
	}

	public function test_temporal_traditional_sql_mode_rejects_zero_dates_in_duckdb(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( "SET SESSION sql_mode = 'TRADITIONAL'" );
		$row = $driver->query( 'SELECT @@SESSION.sql_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( 'STRICT_TRANS_TABLES', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'STRICT_ALL_TABLES', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'NO_ZERO_DATE', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'NO_ZERO_IN_DATE', $row['@@SESSION.sql_mode'] );

		$this->create_duckdb_temporal_mode_table( $driver, 'temporal_traditional_mode' );
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_traditional_mode (id, d, payload) VALUES (1, '0000-00-00', 'zero-date')",
			"Incorrect date value: '0000-00-00'"
		);
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_traditional_mode (id, ts, payload) VALUES (1, '2020-01-00 00:00:00', 'zero-in-date')",
			"Incorrect timestamp value: '2020-01-00 00:00:00'"
		);

		$row = $driver->query( 'SELECT COUNT(*) AS row_count FROM temporal_traditional_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $row['row_count'] );
	}

	public function test_temporal_non_strict_implicit_defaults_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table(
			'temporal_non_strict',
			'NOT NULL',
			array(
				'UNIQUE KEY payload_unique (payload)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(1, 'bad', '18:30:00', 'bad', 'bad', 'invalid-strings'),
				(2, TRUE, '18:30:00.123456', FALSE, 0, 'scalars')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict SET
				id = 3,
				d = 'bad',
				tm = '18:30:00',
				dt = FALSE,
				ts = TRUE,
				payload = 'insert-set'"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(4, '2025-02-01', '18:30:00', '2025-02-01', '2025-02-01', 'normal-update')"
		);
		$this->assertParityRowCount( 'UPDATE temporal_non_strict SET d = NULL, dt = NULL, ts = NULL WHERE id = 4' );

		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(5, '2025-03-01', '18:30:00', '2025-03-01', '2025-03-01', 'odku-target')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(6, '2025-03-02', '18:30:00', '2025-03-02', '2025-03-02', 'odku-target')
			ON DUPLICATE KEY UPDATE d = 'bad', dt = TRUE, ts = 0"
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(7, '2025-03-03', '18:30:00', '2025-03-03', '2025-03-03', 'odku-target')
			ON DUPLICATE KEY UPDATE d = NULL",
			'NOT NULL',
			array( $this->temporal_select_sql( 'temporal_non_strict' ) )
		);

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_non_strict' ) );
	}

	public function test_temporal_non_strict_omitted_defaults_for_insert_values_and_set_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_omitted_defaults', 'NOT NULL' );

		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults (id, payload) VALUES (1, 'values')" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults SET id = 2, payload = 'set'" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults (id, d, payload) VALUES (3, '2025-01-01', 'partial')" );

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_omitted_defaults' ) );
	}

	public function test_temporal_strict_omitted_defaults_still_fail_at_insert_time(): void {
		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_omitted_strict_values', 'NOT NULL' );
		$this->create_temporal_write_table( 'temporal_omitted_strict_set', 'NOT NULL' );

		$this->assertParityRowCount( "SET sql_mode = 'STRICT_TRANS_TABLES'" );
		$this->assertParityErrorContains(
			'INSERT INTO temporal_omitted_strict_values (id) VALUES (1)',
			'NOT NULL'
		);
		$this->assertParityErrorContains(
			'INSERT INTO temporal_omitted_strict_set SET id = 1',
			'NOT NULL'
		);
	}

	public function test_temporal_full_column_insert_order_is_unchanged_by_omitted_defaults(): void {
		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_omitted_full_order (
					id INT,
					d DATE NOT NULL
				)',
			)
		);

		$this->assertParityRowCount( "INSERT INTO temporal_omitted_full_order VALUES (1, '2025-01-01')" );
		$this->assertParityRows( 'SELECT id, d FROM temporal_omitted_full_order ORDER BY id' );
	}

	public function test_temporal_replace_and_odku_conflict_values_are_coerced(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_conflict (
					id INT,
					d DATE NOT NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40),
					UNIQUE KEY d_unique (d)
				)',
				"INSERT INTO temporal_replace_conflict (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'original')",
			)
		);
		$this->assertParityRowCount(
			"REPLACE INTO temporal_replace_conflict (id, d, dt, ts, payload) VALUES
				(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'replaced')"
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_replace_conflict ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_error (
					id INT,
					d DATE NOT NULL,
					payload VARCHAR(40) UNIQUE
				)',
				"INSERT INTO temporal_replace_error (id, d, payload) VALUES (1, '2025-10-23', 'stable')",
			)
		);
		$this->assert_temporal_error_leaves_rows(
			"REPLACE INTO temporal_replace_error (id, d, payload) VALUES (2, 'bad', 'stable')",
			"Incorrect date value: 'bad'",
			array( 'SELECT id, d, payload FROM temporal_replace_error ORDER BY id' )
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_odku_conflict (
					id INT PRIMARY KEY,
					d DATE NOT NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40),
					UNIQUE KEY d_unique (d)
				)',
				"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'original')",
			)
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
				(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'incoming')
			ON DUPLICATE KEY UPDATE d = VALUES(d), dt = VALUES(dt), ts = VALUES(ts), payload = 'updated'"
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_odku_conflict ORDER BY id' );
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
				(3, '2025-10-23', '2025-10-26', '2025-10-26', 'bad-update')
			ON DUPLICATE KEY UPDATE ts = 'bad'",
			"Incorrect timestamp value: 'bad'",
			array( 'SELECT id, d, dt, ts, payload FROM temporal_odku_conflict ORDER BY id' )
		);
	}

	public function test_temporal_odku_date_unique_conflict_uses_coerced_insert_value(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_odku_date_conflict (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_odku_date_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_date_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_odku_date_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_odku_date_unique_conflict_uses_coerced_insert_value(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_odku_date_conflict (
					id INT,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO tmp_temporal_odku_date_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO tmp_temporal_odku_date_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_odku_date_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_odku_prefers_secondary_unique_conflict_over_nonconflicting_primary(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_odku_secondary_conflict (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO tmp_temporal_odku_secondary_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO tmp_temporal_odku_secondary_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_odku_secondary_conflict ORDER BY id' );
	}

	public function test_temporal_odku_omitted_default_unique_conflict_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"SET sql_mode = ''",
				'CREATE TABLE temporal_odku_omitted_conflict (
					id INT PRIMARY KEY,
					d DATE NOT NULL UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_odku_omitted_conflict (id, d, payload) VALUES (1, '0000-00-00', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_omitted_conflict (id, payload) VALUES (2, 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_odku_omitted_conflict ORDER BY id' );
	}

	public function test_temporal_replace_manual_conflicts_use_storage_coerced_values(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_manual_conflict (
					id INT UNIQUE,
					d DATE UNIQUE,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_manual_conflict (id, d, payload) VALUES
					(1, '2025-10-23', 'old-date'),
					(2, '2025-10-24', 'old-id')",
			)
		);

		$this->assertParityRowCount(
			"REPLACE INTO temporal_replace_manual_conflict (id, d, payload) VALUES
				(2, '2025-10-23 18:30:00', 'new')"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_replace_manual_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_replace_manual_conflicts_use_storage_coerced_values(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_replace_manual_conflict (
					id INT UNIQUE,
					d DATE UNIQUE,
					payload VARCHAR(40)
				)',
				"INSERT INTO tmp_temporal_replace_manual_conflict (id, d, payload) VALUES
					(1, '2025-10-23', 'old-date'),
					(2, '2025-10-24', 'old-id')",
			)
		);

		$this->assertParityRowCount(
			"REPLACE INTO tmp_temporal_replace_manual_conflict (id, d, payload) VALUES
				(2, '2025-10-23 18:30:00', 'new')"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_replace_manual_conflict ORDER BY id' );
	}

	public function test_temporal_replace_manual_strict_error_preserves_caller_transaction(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_manual_tx (
					id INT UNIQUE,
					d DATE,
					payload VARCHAR(40),
					UNIQUE KEY payload_key (payload)
				)',
				"INSERT INTO temporal_replace_manual_tx (id, d, payload) VALUES (1, '2025-10-23', 'old')",
				'START TRANSACTION',
			)
		);

		$this->assert_temporal_error_leaves_rows(
			"REPLACE INTO temporal_replace_manual_tx (id, d, payload) VALUES (1, 'not-a-date', 'new')",
			"Incorrect date value: 'not-a-date'",
			array( 'SELECT id, d, payload FROM temporal_replace_manual_tx ORDER BY id' )
		);
		$this->runParitySetup( array( 'ROLLBACK' ) );
	}

	public function test_temporal_joined_update_writes_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_join_posts (
					id INT,
					d DATE NULL,
					tm TIME NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40)
				)',
				'CREATE TABLE temporal_join_updates (
					post_id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					flag VARCHAR(20)
				)',
				"INSERT INTO temporal_join_posts (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'target-first-old'),
					(2, '2025-01-02', '18:30:00', '2025-01-02', '2025-01-02', 'comma-old'),
					(3, '2025-01-03', '18:30:00', '2025-01-03', '2025-01-03', 'non-first-old'),
					(4, '2025-01-04', '18:30:00', '2025-01-04', '2025-01-04', 'invalid-old'),
					(5, '2025-01-05', '18:30:00', '2025-01-05', '2025-01-05', 'derived-old')",
				"INSERT INTO temporal_join_updates VALUES
					(1, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-23', '2025-10-23 18:30:00.123456', 'target-first'),
					(2, '2025-10-24', '18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'comma'),
					(3, '2025-10-25 09:08:07.123456', '18:30:00', '2025-10-25', '2025-10-25', 'non-first'),
					(4, 'bad', '18:30:00', 'bad', 'bad', 'invalid'),
					(5, '2025-10-26', '18:30:00', '2025-10-26 04:05:06.123456', '2025-10-26', 'derived')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.d = u.d_text, p.tm = u.tm_text, p.dt = u.dt_text, p.ts = u.ts_text, p.payload = 'target-first'
			WHERE u.flag = 'target-first'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p, temporal_join_updates u
			SET p.dt = u.dt_text, p.ts = u.ts_text, p.payload = 'comma'
			WHERE p.id = u.post_id AND u.flag = 'comma'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_updates u
			JOIN temporal_join_posts p ON p.id = u.post_id
			SET p.d = u.d_text, p.payload = 'non-first'
			WHERE u.flag = 'non-first'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p
			JOIN (
				SELECT post_id, dt_text
				FROM temporal_join_updates
				WHERE flag = 'derived'
			) u ON u.post_id = p.id
			SET p.dt = u.dt_text, p.payload = 'derived'"
		);
		$this->assertParityRows( $this->temporal_join_posts_select_sql() );

		$stable_selects = array(
			$this->temporal_join_posts_select_sql(),
			'SELECT post_id, d_text, tm_text, dt_text, ts_text, flag FROM temporal_join_updates ORDER BY post_id',
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.dt = u.dt_text
			WHERE u.flag = 'invalid'",
			"Incorrect datetime value: 'bad'",
			$stable_selects
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.dt = '2025-12-01', u.dt_text = '2025-12-01'
			WHERE p.id = 1",
			'UPDATE statement modifying multiple tables',
			$stable_selects
		);
	}

	public function test_case_insensitive_unique_conflicts_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE ci_items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY name (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO ci_items (id, name, payload) VALUES (1, 'first', 'a')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount( "INSERT IGNORE INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'ignored')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount(
			"INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE name = VALUES(name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO ci_items (id, name, payload) VALUES (2, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );
	}

	public function test_temporary_case_insensitive_unique_conflicts_use_temp_metadata(): void {
		$this->runParitySetup(
			array(
				"CREATE TEMPORARY TABLE tmp_ci_items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY name (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO tmp_ci_items (id, name, payload) VALUES (1, 'first', 'a')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO tmp_ci_items (id, name, payload) VALUES (2, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );

		$this->assertParityRowCount(
			"INSERT INTO tmp_ci_items (id, name, payload) VALUES (2, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE name = VALUES(name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO tmp_ci_items (id, name, payload) VALUES (2, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );
	}

	public function test_case_insensitive_composite_unique_conflicts_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE site_options (
					id INTEGER PRIMARY KEY,
					site_id INTEGER NOT NULL DEFAULT 0,
					option_name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY site_option (site_id, option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (1, 1, 'first', 'a')",
				"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (2, 2, 'FIRST', 'other-site')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);

		$this->assertParityRowCount(
			"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, site_id, option_name, payload FROM site_options ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, site_id, option_name, payload FROM site_options ORDER BY id' );
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

	public function test_show_columns_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
	}

	public function test_show_columns_qualified_and_filtered_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		foreach (
			array(
				'SHOW COLUMNS FROM wp.metadata',
				"SHOW COLUMNS FROM `wp`.`metadata` LIKE 'option_%'",
				"SHOW COLUMNS FROM information_schema.metadata FROM wp WHERE Field = 'option_name'",
				"SHOW COLUMNS FROM wp.metadata WHERE Field = 'autoload'",
				"SHOW COLUMNS FROM wp.metadata WHERE Type = 'longtext'",
				"SHOW COLUMNS FROM wp.metadata WHERE `Null` = 'NO' AND `Key` = 'PRI'",
				"SHOW COLUMNS FROM wp.metadata WHERE `Default` = 'yes'",
				"SHOW COLUMNS FROM wp.metadata WHERE Extra = 'auto_increment'",
				'SHOW FULL COLUMNS FROM wp.metadata WHERE `Collation` IS NOT NULL',
				"SHOW FULL FIELDS IN `wp`.`metadata` WHERE `Comment` = 'Option name'",
				'DESCRIBE wp.metadata',
				"DESCRIBE `wp`.`metadata` 'option_%'",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_alter_table_add_column_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata (option_name, option_value) VALUES ('siteurl', 'https://example.test')",
				"ALTER TABLE metadata ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
			)
		);

		$this->assertParityRows( 'SELECT option_name, option_value, autoload FROM metadata' );
		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
	}

	public function test_alter_table_change_modify_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					option_name VARCHAR(255) DEFAULT '7',
					option_value LONGTEXT,
					autoload VARCHAR(10) DEFAULT 'no'
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata (option_name, option_value, autoload) VALUES ('7', 'payload', 'no')",
				'ALTER TABLE metadata CHANGE COLUMN option_name option_name SMALLINT NOT NULL DEFAULT 14',
				"ALTER TABLE metadata MODIFY COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
				'ALTER TABLE metadata CHANGE COLUMN option_value option_payload LONGTEXT NULL',
				'INSERT INTO metadata (option_payload) VALUES (\'defaulted\')',
			)
		);

		$this->assertParityRows( 'SELECT option_name, option_payload, autoload FROM metadata ORDER BY option_payload' );
		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, COLUMN_KEY, EXTRA
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY ordinal_position"
		);
	}

	public function test_alter_table_change_modify_key_rebuild_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata_key (
					id VARCHAR(20) NOT NULL,
					code VARCHAR(20) DEFAULT '7',
					payload VARCHAR(20),
					PRIMARY KEY (id),
					KEY code_idx (code)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata_key (id, code, payload) VALUES ('1', '10', 'alpha'), ('2', '11', 'bravo')",
				'ALTER TABLE metadata_key CHANGE COLUMN id id INT NOT NULL',
				"ALTER TABLE metadata_key MODIFY COLUMN code SMALLINT NOT NULL DEFAULT 42 COMMENT 'Numeric code'",
				"INSERT INTO metadata_key (id, payload) VALUES (3, 'charlie')",
			)
		);

		$this->assertParityRows( 'SELECT id, code, payload FROM metadata_key ORDER BY id' );
		$this->assertParityRows(
			"SELECT column_name, column_type, is_nullable, column_default, extra
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'metadata_key'
			ORDER BY ordinal_position"
		);
		$this->assertParityRowColumns(
			'SHOW INDEX FROM metadata_key',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT index_name AS INDEX_NAME, column_name AS COLUMN_NAME, non_unique AS NON_UNIQUE, sub_part AS SUB_PART
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'metadata_key'
			ORDER BY CASE WHEN index_name = 'PRIMARY' THEN 0 ELSE 1 END, index_name, seq_in_index"
		);
	}

	public function test_information_schema_columns_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					UNIQUE KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"ALTER TABLE metadata ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE,
				COLUMN_KEY, EXTRA, PRIVILEGES, COLUMN_COMMENT, GENERATION_EXPRESSION, SRS_ID
			FROM information_schema.columns
			WHERE table_name = 'metadata'
			ORDER BY ordinal_position"
		);
		$this->assertParityRows(
			"SELECT c.COLUMN_NAME, c.COLUMN_TYPE
			FROM information_schema.columns c
			WHERE c.TABLE_SCHEMA = 'wp' AND c.TABLE_NAME = 'metadata'
			ORDER BY c.ORDINAL_POSITION"
		);
	}

	public function test_information_schema_statistics_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					nullable_value VARCHAR(191),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload),
					KEY nullable_value (nullable_value),
					KEY option_value_prefix (option_value(12))
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, NON_UNIQUE, INDEX_SCHEMA, INDEX_NAME,
				SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY, SUB_PART, PACKED, NULLABLE,
				INDEX_TYPE, COMMENT, INDEX_COMMENT, IS_VISIBLE, EXPRESSION
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT s.INDEX_NAME, s.COLUMN_NAME, s.SUB_PART
			FROM information_schema.statistics s
			WHERE s.TABLE_SCHEMA = 'wp' AND s.TABLE_NAME = 'metadata'
			ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX"
		);
	}

	public function test_information_schema_constraint_metadata_matches_sqlite(): void {
		$this->assertParityRows(
			"SELECT COUNT(*) AS count
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'"
		);
		$this->assertParityRows(
			"SELECT COUNT(*) AS count
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'"
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE empty_table (id INT, note TEXT)',
				"CREATE TABLE metadata (
					site_id BIGINT(20) UNSIGNED NOT NULL,
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					payload LONGTEXT,
					PRIMARY KEY (site_id, option_id),
					UNIQUE KEY unique_site_option (site_id, option_name),
					KEY payload_prefix (payload(12))
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_NAME, CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'empty_table'"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA,
				TABLE_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
				TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityRows(
			"SELECT tc.CONSTRAINT_NAME AS name, tc.CONSTRAINT_TYPE AS type,
				k.COLUMN_NAME AS col, k.ORDINAL_POSITION AS pos
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.key_column_usage AS k
				ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
				AND k.TABLE_SCHEMA = tc.TABLE_SCHEMA
				AND k.TABLE_NAME = tc.TABLE_NAME
			WHERE tc.TABLE_SCHEMA = 'wp' AND tc.TABLE_NAME = 'metadata'
			ORDER BY name, pos"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND constraint_name = 'payload_prefix'"
		);
	}

	public function test_table_level_check_constraints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE check_metadata (
					id INT,
					amount INT,
					CONSTRAINT amount_positive CHECK (amount > 0),
					CHECK (id IS NULL OR id >= 0)
				)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO check_metadata (id, amount) VALUES (1, 10)' );
		$this->assertParityErrorContains(
			'INSERT INTO check_metadata (id, amount) VALUES (2, -1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'INSERT INTO check_metadata (id, amount) VALUES (-1, 1)',
			'CHECK constraint failed'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'check_metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, cc.CHECK_CLAUSE
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.check_constraints AS cc
				ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
			WHERE tc.table_schema = 'wp' AND tc.table_name = 'check_metadata'
			ORDER BY tc.constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE check_metadata' );
	}

	public function test_inline_check_constraints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE inline_check_metadata (
					id INT CHECK (id >= 0),
					amount INT CHECK (amount > 0) ENFORCED
				)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO inline_check_metadata (id, amount) VALUES (1, 10)' );
		$this->assertParityErrorContains(
			'INSERT INTO inline_check_metadata (id, amount) VALUES (-1, 1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'INSERT INTO inline_check_metadata (id, amount) VALUES (2, -1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_check_metadata SET amount = 0 WHERE id = 1',
			'CHECK constraint failed'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'inline_check_metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE inline_check_metadata' );
	}

	public function test_simple_table_level_foreign_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE fk_parent (id INT PRIMARY KEY)',
				'CREATE TABLE fk_child (
					id INT,
					parent_id INT,
					CONSTRAINT fk_parent_ref FOREIGN KEY (parent_id) REFERENCES fk_parent (id) ON DELETE RESTRICT ON UPDATE NO ACTION
				)',
				'CREATE TABLE fk_child_generated (
					id INT,
					parent_id INT,
					FOREIGN KEY (parent_id) REFERENCES fk_parent (id)
				)',
				'INSERT INTO fk_parent (id) VALUES (1)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO fk_child (id, parent_id) VALUES (10, 1)' );
		$this->assertParityRowCount( 'INSERT INTO fk_child_generated (id, parent_id) VALUES (20, 1)' );
		$this->assertParityErrorContains(
			'INSERT INTO fk_child (id, parent_id) VALUES (11, 404)',
			'constraint'
		);
		$this->assertParityErrorContains(
			'DELETE FROM fk_parent WHERE id = 1',
			'constraint'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME,
				UNIQUE_CONSTRAINT_CATALOG, UNIQUE_CONSTRAINT_SCHEMA,
				UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE,
				TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name, ordinal_position"
		);
		$this->assertParityRows(
			"SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME,
				k.REFERENCED_COLUMN_NAME
			FROM information_schema.referential_constraints AS rc
			JOIN information_schema.key_column_usage AS k
				ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
				AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
			WHERE rc.constraint_schema = 'wp'
			ORDER BY rc.table_name, rc.constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE fk_child' );
		$this->assertParityRows( 'SHOW CREATE TABLE fk_child_generated' );
	}

	public function test_simple_inline_foreign_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE inline_fk_parent (id INT PRIMARY KEY)',
				'CREATE TABLE inline_fk_child (
					id INT,
					parent_id INT REFERENCES inline_fk_parent (id) ON DELETE RESTRICT ON UPDATE NO ACTION
				)',
				'CREATE TABLE inline_fk_child_default (
					id INT,
					parent_id INT REFERENCES inline_fk_parent (id)
				)',
				'INSERT INTO inline_fk_parent (id) VALUES (1)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO inline_fk_child (id, parent_id) VALUES (10, 1)' );
		$this->assertParityRowCount( 'INSERT INTO inline_fk_child_default (id, parent_id) VALUES (20, 1)' );
		$this->assertParityErrorContains(
			'INSERT INTO inline_fk_child (id, parent_id) VALUES (11, 404)',
			'constraint'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_fk_child SET parent_id = 404 WHERE id = 10',
			'constraint'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_fk_parent SET id = 2 WHERE id = 1',
			'constraint'
		);
		$this->assertParityErrorContains(
			'DELETE FROM inline_fk_parent WHERE id = 1',
			'constraint'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION,
				UPDATE_RULE, DELETE_RULE, TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name, ordinal_position"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE inline_fk_child' );
		$this->assertParityRows( 'SHOW CREATE TABLE inline_fk_child_default' );
	}

	public function test_alter_table_check_constraint_actions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_check_gap (id INT, label VARCHAR(20), CONSTRAINT existing_check CHECK (id >= 0), KEY label_idx (label))',
				"INSERT INTO alter_check_gap (id, label) VALUES (1, 'one')",
				'ALTER TABLE alter_check_gap ADD CONSTRAINT added_check CHECK (id < 10)',
				'ALTER TABLE alter_check_gap ADD CHECK (id IS NULL OR id <> 7)',
			)
		);

		$this->assertParityRows( 'SHOW CREATE TABLE alter_check_gap' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_check_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityErrorContains(
			"INSERT INTO alter_check_gap (id, label) VALUES (20, 'too_high')",
			'CHECK constraint failed'
		);
		$this->assertParityRowColumns(
			'SHOW INDEX FROM alter_check_gap',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);

		$this->assertParityRowCount( 'ALTER TABLE alter_check_gap DROP CHECK existing_check' );
		$this->assertParityRowCount( 'ALTER TABLE alter_check_gap DROP CONSTRAINT added_check' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_check_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE alter_check_gap' );
		$this->assertParityRowCount( "INSERT INTO alter_check_gap (id, label) VALUES (-1, 'after_drop')" );
	}

	public function test_unique_key_foreign_key_metadata_documents_current_duckdb_gap(): void {
		$sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => ':memory:' ) ),
			'wp'
		);
		$duckdb_driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$parent_sql = 'CREATE TABLE unique_parent (
			id INT PRIMARY KEY,
			code INT,
			UNIQUE KEY code_u (code)
		)';
		$child_sql  = 'CREATE TABLE unique_child (
			id INT,
			parent_code INT,
			CONSTRAINT fk_parent_code FOREIGN KEY (parent_code) REFERENCES unique_parent (code)
		)';

		$sqlite_driver->query( $parent_sql, PDO::FETCH_ASSOC );
		$duckdb_driver->query( $parent_sql );
		$sqlite_driver->query( $child_sql, PDO::FETCH_ASSOC );

		try {
			$duckdb_driver->query( $child_sql );
			$this->fail( 'Expected DuckDB to reject a foreign key referencing a driver-managed unique index.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'primary key or unique constraint', strtolower( $e->getMessage() ) );
		}

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'fk_parent_code',
					'UNIQUE_CONSTRAINT_NAME' => 'code_u',
					'TABLE_NAME'             => 'unique_child',
					'REFERENCED_TABLE_NAME'  => 'unique_parent',
				),
			),
			$sqlite_driver->query(
				"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp' AND table_name = 'unique_child'
				ORDER BY constraint_name",
				PDO::FETCH_ASSOC
			)
		);

		$sqlite_usage = $sqlite_driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_NAME,
				REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'unique_child'
			ORDER BY constraint_name, ordinal_position",
			PDO::FETCH_ASSOC
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'               => 'fk_parent_code',
					'TABLE_NAME'                    => 'unique_child',
					'COLUMN_NAME'                   => 'parent_code',
					'POSITION_IN_UNIQUE_CONSTRAINT' => '1',
					'REFERENCED_TABLE_NAME'         => 'unique_parent',
					'REFERENCED_COLUMN_NAME'        => 'code',
				),
			),
			$sqlite_usage
		);
	}

	public function test_information_schema_tables_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				'CREATE TABLE plain (id INT, name TEXT)',
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, VERSION,
				ROW_FORMAT, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, MAX_DATA_LENGTH,
				INDEX_LENGTH, DATA_FREE, `AUTO_INCREMENT`, UPDATE_TIME, CHECK_TIME,
				TABLE_COLLATION, CHECKSUM, CREATE_OPTIONS, TABLE_COMMENT
			FROM information_schema.tables
			WHERE table_schema = 'wp'
			ORDER BY table_name"
		);
		$this->assertParityRows(
			"SELECT t.TABLE_NAME, t.ENGINE, t.`AUTO_INCREMENT`
			FROM information_schema.tables t
			WHERE t.TABLE_SCHEMA = 'wp'
			ORDER BY t.TABLE_NAME"
		);
	}

	public function test_check_table_status_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE check_items (id INT)',
				'CREATE TABLE check_second (id INT)',
				'CREATE TEMPORARY TABLE check_temp_only (id INT)',
				'INSERT INTO check_items VALUES (1)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM check_items' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'CHECK TABLE check_items' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'CHECK TABLE wp.check_items' );
		$this->assertParityRows( 'CHECK TABLES check_items' );
		$this->assertParityRows( 'CHECK TABLE check_items QUICK FAST MEDIUM EXTENDED CHANGED FOR UPGRADE' );
		$this->assertParityRows( 'CHECK TABLE check_items, check_second' );
		$this->assertParityRows( 'CHECK TABLE check_temp_only' );
		$this->assertParityRows( 'CHECK TABLE missing_check_table' );
		$this->assertParityRows( 'CHECK TABLE check_items, missing_check_table' );
		$this->assertParityErrorContains( 'CHECK TABLE information_schema.tables', "to database 'information_schema'" );
	}

	public function test_administration_table_status_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE admin_items (id INT)',
				'CREATE TABLE admin_second (id INT)',
				'CREATE TEMPORARY TABLE admin_temp_only (id INT)',
				'CREATE TABLE admin_shadow (base_id INT)',
				'CREATE TEMPORARY TABLE admin_shadow (temp_id INT)',
				'INSERT INTO admin_items VALUES (1)',
				'INSERT INTO admin_shadow VALUES (9)',
			)
		);

		foreach ( array( 'ANALYZE TABLE', 'OPTIMIZE TABLE', 'REPAIR TABLE' ) as $statement ) {
			$this->assertParityRows( 'SELECT id FROM admin_items' );
			$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
			$this->assertParityRows( $statement . ' admin_items' );
			$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
			$this->assertParityRows( $statement . ' wp.admin_items' );
			$this->assertParityRows( str_replace( ' TABLE', ' TABLES', $statement ) . ' admin_items' );
			$this->assertParityRows( $statement . ' admin_items, admin_second' );
			$this->assertParityRows( $statement . ' admin_temp_only' );
			$this->assertParityRows( $statement . ' admin_shadow' );
			$this->assertParityRows( $statement . ' missing_admin_table' );
			$this->assertParityRows( $statement . ' admin_items, missing_admin_table' );
			$this->assertParityErrorContains( $statement . ' information_schema.tables', "to database 'information_schema'" );
			$this->assertParityErrorContains( $statement . ' admin_items, information_schema.tables', "to database 'information_schema'" );
		}

		$this->assertParityRows( 'ANALYZE LOCAL TABLE admin_items' );
		$this->assertParityRows( 'ANALYZE NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'OPTIMIZE LOCAL TABLE admin_items' );
		$this->assertParityRows( 'OPTIMIZE NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'REPAIR LOCAL TABLE admin_items' );
		$this->assertParityRows( 'REPAIR NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'REPAIR TABLE admin_items QUICK' );
		$this->assertParityRows( 'REPAIR TABLE admin_items EXTENDED' );
		$this->assertParityRows( 'REPAIR TABLE admin_items USE_FRM' );
		$this->assertParityRows( 'REPAIR TABLE admin_items QUICK EXTENDED USE_FRM' );
		$this->assertParityRows( 'ANALYZE TABLE admin_items UPDATE HISTOGRAM ON id' );
		$this->assertParityRows( 'ANALYZE TABLE admin_items DROP HISTOGRAM ON id' );
		$this->assertParityErrorContains( 'OPTIMIZE TABLE admin_items QUICK', 'parse' );

		$this->assertParityRows(
			"SELECT TABLE_NAME
			FROM information_schema.tables
			WHERE TABLE_NAME = 'admin_temp_only'
				OR TABLE_NAME LIKE '__wp_duckdb_%'
			ORDER BY TABLE_NAME"
		);
		$this->assertParityRows( 'SELECT temp_id FROM admin_shadow' );
	}

	public function test_show_table_status_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				'CREATE TABLE plain (id INT, name TEXT)',
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
			)
		);

		$columns = array(
			'Name',
			'Engine',
			'Version',
			'Row_format',
			'Rows',
			'Avg_row_length',
			'Data_length',
			'Max_data_length',
			'Index_length',
			'Data_free',
			'Auto_increment',
			'Update_time',
			'Check_time',
			'Collation',
			'Checksum',
			'Create_options',
			'Comment',
		);

		$this->assertParityRowColumns( 'SHOW TABLE STATUS FROM wp', $columns );
		$this->assertParityRowColumns( "SHOW TABLE STATUS IN wp LIKE 'plain'", $columns );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS WHERE `Auto_increment` > 2', array( 'Name', 'Auto_increment' ) );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS WHERE `Auto_increment` IS NULL', array( 'Name', 'Auto_increment' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS WHERE SUBSTR(Name, 1, 4) = 'meta'", array( 'Name' ) );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS FROM other_database', $columns );
	}

	public function test_show_create_table_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (id),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload),
					KEY option_value_prefix (option_value(12))
				) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
				"CREATE TABLE composite_pk (
					site_id BIGINT(20) UNSIGNED NOT NULL,
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					PRIMARY KEY (site_id, option_id),
					UNIQUE KEY unique_site_option (site_id, option_name)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
				'CREATE TABLE plain (id INT, name TEXT)',
			)
		);

		$this->assertParityRows( 'SHOW CREATE TABLE metadata' );
		$this->assertParityRows( 'SHOW CREATE TABLE wp.metadata' );
		$this->assertParityRows( 'SHOW CREATE TABLE composite_pk' );
		$this->assertParityRows( 'SHOW CREATE TABLE plain' );
		$this->assertParityRows( 'SHOW CREATE TABLE missing' );
	}

	public function test_drop_table_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_drop' ),
				'CREATE TABLE survivor (id INT, note VARCHAR(20))',
				"INSERT INTO lifecycle_drop (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')",
				'DROP TABLE lifecycle_drop',
			)
		);

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_drop' );
		$this->assertParityRows(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT column_name
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT index_name
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT constraint_name
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);

		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_drop' ),
				"INSERT INTO lifecycle_drop (name, payload) VALUES ('fresh', 'value')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM lifecycle_drop ORDER BY id' );
	}

	public function test_truncate_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_truncate' ),
				"INSERT INTO lifecycle_truncate (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')",
				'DELETE FROM lifecycle_truncate WHERE name = \'b\'',
			)
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);

		$this->runParitySetup( array( 'TRUNCATE TABLE wp.lifecycle_truncate' ) );
		$this->assertParityRows( 'SELECT COUNT(*) AS count FROM lifecycle_truncate' );
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_truncate' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_truncate',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'lifecycle_truncate'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_truncate' );

		$this->runParitySetup( array( "INSERT INTO lifecycle_truncate (name, payload) VALUES ('z', 'zulu')" ) );
		$this->assertParityRows( 'SELECT id, name FROM lifecycle_truncate ORDER BY id' );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);
	}

	public function test_create_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(20)
				) AUTO_INCREMENT=100',
				'CREATE TABLE seeded_plain (id INT, name VARCHAR(20)) AUTO_INCREMENT=500',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO seeded_ai (name) VALUES ('a')" ) );
		$this->assertParityRows( 'SELECT id, name FROM seeded_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'seeded_ai'"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE seeded_ai' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_plain'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE seeded_plain' );
	}

	public function test_alter_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				)',
				'ALTER TABLE alter_ai AUTO_INCREMENT = 50',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO alter_ai (name) VALUES ('first')" ) );
		$this->assertParityRows( 'SELECT id, name FROM alter_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );

		$this->runParitySetup( array( 'ALTER TABLE alter_ai AUTO_INCREMENT = 200' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_ai' );

		$this->runParitySetup( array( 'ALTER TABLE alter_ai AUTO_INCREMENT = 1' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO alter_ai (name) VALUES ('second')" ) );
		$this->assertParityRows( 'SELECT id, name FROM alter_ai ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE alter_plain (id INT, name TEXT) AUTO_INCREMENT=500',
				'ALTER TABLE alter_plain AUTO_INCREMENT = 900',
			)
		);
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_plain'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_plain' );
	}

	public function test_temporary_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE shadow_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				)',
				"INSERT INTO shadow_ai (name) VALUES ('p1'), ('p2')",
				'CREATE TEMPORARY TABLE shadow_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				) AUTO_INCREMENT=500',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'shadow_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'shadow_ai'"
		);

		$this->runParitySetup( array( "INSERT INTO shadow_ai (name) VALUES ('temp-a')" ) );
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
		$this->runParitySetup(
			array(
				'ALTER TABLE shadow_ai AUTO_INCREMENT = 1000',
				"INSERT INTO shadow_ai (name) VALUES ('temp-b')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'shadow_ai'", array( 'Name', 'Auto_increment' ) );

		$this->runParitySetup(
			array(
				'DROP TEMPORARY TABLE shadow_ai',
				"INSERT INTO shadow_ai (name) VALUES ('p3')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
	}

	public function test_drop_index_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_idx' ),
				"INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'alpha')",
				'DROP INDEX payload_prefix ON lifecycle_idx',
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_idx' );
		$this->assertParityRows(
			"SELECT index_name, column_name, sub_part
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_idx' );

		$this->runParitySetup(
			array(
				'DROP INDEX name_unique ON wp.lifecycle_idx',
				"INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'duplicate')",
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_idx' );
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_idx' );
		$this->assertParityRows( 'SELECT name FROM lifecycle_idx ORDER BY id' );
	}

	public function test_alter_table_drop_primary_key_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk (
					id INT NOT NULL,
					name VARCHAR(20) NOT NULL,
					amount INT,
					PRIMARY KEY (id),
					UNIQUE KEY name_unique (name),
					KEY amount_idx (amount),
					CONSTRAINT amount_positive CHECK (amount > 0)
				)',
				"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (1, 'a', 10), (2, 'b', 20)",
				'ALTER TABLE ddl_drop_pk DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (1, 'c', 30)",
			)
		);

		$this->assertParityRows( 'SELECT id, name, amount FROM ddl_drop_pk ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk' );
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk' );
		$this->assertParityRows(
			"SELECT index_name, column_name, non_unique
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT constraint_name, column_name, referenced_table_name
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (3, 'a', 40)",
			'constraint'
		);
		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (4, 'd', 0)",
			'CHECK constraint failed'
		);
	}

	public function test_alter_table_drop_primary_index_quoted_aliases_match_sqlite(): void {
		foreach ( array( 'DROP INDEX `PRIMARY`', 'DROP KEY `PRIMARY`' ) as $drop_action ) {
			$table_name = 'ddl_drop_pk_alias_' . strtolower( str_replace( array( 'DROP ', ' `PRIMARY`' ), '', $drop_action ) );
			$this->runParitySetup(
				array(
					"CREATE TABLE {$table_name} (
						id INT NOT NULL,
						name VARCHAR(20),
						PRIMARY KEY (id),
						KEY name_idx (name)
					)",
					"INSERT INTO {$table_name} (id, name) VALUES (1, 'a'), (2, 'b')",
					"ALTER TABLE {$table_name} {$drop_action}",
					"INSERT INTO {$table_name} (id, name) VALUES (1, 'duplicate')",
				)
			);

			$this->assertParityRows( "SELECT id, name FROM {$table_name} ORDER BY id, name" );
			$this->assertParityRows( "SHOW COLUMNS FROM {$table_name}" );
			$this->assertParityRowColumns(
				"SHOW INDEX FROM {$table_name}",
				array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
			);
			$this->assertParityRows( "SHOW CREATE TABLE {$table_name}" );
		}
	}

	public function test_alter_table_drop_primary_index_unquoted_aliases_reject_without_mutation_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_unquoted (id INT PRIMARY KEY, name VARCHAR(20), KEY name_idx (name))',
				"INSERT INTO ddl_drop_pk_unquoted (id, name) VALUES (1, 'a')",
			)
		);

		foreach ( array( 'DROP INDEX PRIMARY', 'DROP KEY PRIMARY' ) as $drop_action ) {
			$this->assertParityErrorContains(
				'ALTER TABLE ddl_drop_pk_unquoted ' . $drop_action,
				'parse'
			);
			$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_unquoted ORDER BY id' );
			$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_unquoted' );
			$this->assertParityRowColumns(
				'SHOW INDEX FROM ddl_drop_pk_unquoted',
				array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
			);
			$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_unquoted' );
		}
	}

	public function test_alter_table_drop_composite_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_composite_pk (
					site_id INT NOT NULL,
					object_id INT NOT NULL,
					name VARCHAR(20),
					PRIMARY KEY (site_id, object_id),
					KEY name_idx (name)
				)',
				"INSERT INTO ddl_drop_composite_pk (site_id, object_id, name) VALUES (1, 10, 'a'), (1, 11, 'b')",
				'ALTER TABLE ddl_drop_composite_pk DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_composite_pk (site_id, object_id, name) VALUES (1, 10, 'duplicate')",
			)
		);

		$this->assertParityRows( 'SELECT site_id, object_id, name FROM ddl_drop_composite_pk ORDER BY site_id, object_id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_composite_pk' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_composite_pk',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_composite_pk' );
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_composite_pk'
			ORDER BY constraint_name"
		);
	}

	public function test_alter_table_drop_auto_increment_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_ai (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(20) NOT NULL,
					KEY name_idx (name)
				)',
				"INSERT INTO ddl_drop_pk_ai (name) VALUES ('a'), ('b')",
				'ALTER TABLE ddl_drop_pk_ai DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk_ai (name) VALUES ('generated_after_drop')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk_ai (id, name) VALUES (1, 'explicit_duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_ai ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_ai' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_ai',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk_ai'"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_ai' );
	}

	public function test_alter_table_drop_primary_key_temporary_shadow_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_shadow (id INT PRIMARY KEY, name VARCHAR(20))',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (1, 'persistent')",
				'CREATE TEMPORARY TABLE ddl_drop_pk_shadow (id INT PRIMARY KEY, name VARCHAR(20), KEY name_idx (name))',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (2, 'temporary')",
				'ALTER TABLE ddl_drop_pk_shadow DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (2, 'temporary_duplicate')",
			)
		);

		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_shadow ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_shadow' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_shadow',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_shadow' );

		$this->runParitySetup( array( 'DROP TEMPORARY TABLE ddl_drop_pk_shadow' ) );
		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_shadow ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_shadow' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_shadow',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_shadow' );
	}

	private function create_temporal_write_table( string $table_name, string $nullability = 'NULL', array $extra_definitions = array() ): void {
		$definitions = array_merge(
			array(
				'id INT PRIMARY KEY',
				'd DATE ' . $nullability,
				'tm TIME ' . $nullability,
				'dt DATETIME ' . $nullability,
				'ts TIMESTAMP ' . $nullability,
				'payload VARCHAR(40)',
			),
			$extra_definitions
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE ' . $table_name . " (\n\t"
				. implode( ",\n\t", $definitions )
				. "\n)",
			)
		);
	}

	private function temporal_select_sql( string $table_name ): string {
		return 'SELECT id, d, tm, dt, ts, payload FROM ' . $table_name . ' ORDER BY id';
	}

	private function temporal_join_posts_select_sql(): string {
		return 'SELECT id, d, tm, dt, ts, payload FROM temporal_join_posts ORDER BY id';
	}

	private function assert_temporal_error_leaves_rows( string $sql, string $needle, array $select_queries ): void {
		$this->assertParityErrorContains( $sql, $needle );
		foreach ( $select_queries as $select_query ) {
			$this->assertParityRows( $select_query );
		}
	}

	private function create_duckdb_temporal_mode_table( WP_DuckDB_Driver $driver, string $table_name ): void {
		$driver->query(
			'CREATE TABLE ' . $table_name . ' (
				id INT PRIMARY KEY,
				d DATE NULL,
				dt DATETIME NULL,
				ts TIMESTAMP NULL,
				payload VARCHAR(40)
			)'
		);
	}

	private function assert_duckdb_error_contains( WP_DuckDB_Driver $driver, string $sql, string $needle ): void {
		try {
			$driver->query( $sql );
		} catch ( Throwable $e ) {
			$this->assertStringContainsString( $needle, $e->getMessage() );
			return;
		}

		$this->fail( 'DuckDB query should have failed for SQL: ' . $sql );
	}

	private function assert_no_select_write_stage_tables( WP_DuckDB_Driver $driver ): void {
		$this->assertSame(
			array(),
			$driver->get_connection()->query(
				"SELECT table_name FROM information_schema.tables
				WHERE table_type = 'LOCAL TEMPORARY'
					AND table_name LIKE '\\_\\_wp\\_duckdb\\_%select\\_src\\_%' ESCAPE '\\'
				ORDER BY table_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	private function createJoinedUpdateGapDrivers(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$sqlite_driver = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'path' => ':memory:' ) ),
			'wp'
		);
		$duckdb_driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$setup_queries = array(
			'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
			'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
			"INSERT INTO t1 VALUES
				(1, 'a1', 10),
				(2, 'a2', 20),
				(3, 'a3', 30),
				(4, 'a4', 40)",
			"INSERT INTO t2 VALUES
				(1, 'b1', 1),
				(3, 'b3', 1),
				(5, 'b5', 1)",
		);

		foreach ( $setup_queries as $query ) {
			$sqlite_driver->query( $query, PDO::FETCH_ASSOC );
			$duckdb_driver->query( $query );
		}

		return array(
			'sqlite' => $sqlite_driver,
			'duckdb' => $duckdb_driver,
		);
	}

	private function assertDuckDBGapQueryRejected( WP_DuckDB_Driver $driver, string $sql, string $message ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		try {
			$driver->query( $sql );
			$this->fail( 'Expected DuckDB joined UPDATE rejection for SQL: ' . $sql );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}
	}

	private function joinedUpdateGapInitialDuckDBRows(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'id'      => 1,
				'note'    => 'a1',
				'only_t1' => 10,
			),
			array(
				'id'      => 2,
				'note'    => 'a2',
				'only_t1' => 20,
			),
			array(
				'id'      => 3,
				'note'    => 'a3',
				'only_t1' => 30,
			),
			array(
				'id'      => 4,
				'note'    => 'a4',
				'only_t1' => 40,
			),
		);
	}

	private function joinedUpdateGapInitialDuckDBSourceRows(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'id'   => 1,
				'note' => 'b1',
				'flag' => 1,
			),
			array(
				'id'   => 3,
				'note' => 'b3',
				'flag' => 1,
			),
			array(
				'id'   => 5,
				'note' => 'b5',
				'flag' => 1,
			),
		);
	}

	private function lifecycleTableSql( string $table_name ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			payload LONGTEXT,
			PRIMARY KEY (id),
			UNIQUE KEY name_unique (name),
			KEY payload_prefix (payload(12))
		) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
	}
}
