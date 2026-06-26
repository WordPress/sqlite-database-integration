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
		$this->assertParityRows( 'SHOW CREATE TABLE check_metadata' );
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
				'INSERT INTO fk_parent (id) VALUES (1)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO fk_child (id, parent_id) VALUES (10, 1)' );
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
			WHERE table_schema = 'wp' AND table_name = 'fk_child'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'fk_child'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE fk_child' );
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
