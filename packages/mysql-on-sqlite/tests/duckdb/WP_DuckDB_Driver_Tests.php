<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * @group duckdb
 */
class WP_DuckDB_Driver_Tests extends WP_DuckDB_TestCase {
	public function test_select_mysql_functions_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp_tests',
			)
		);

		$row = $driver->query( 'SELECT DATABASE() AS db_name, VERSION() AS mysql_version, RAND() AS r, UNIX_TIMESTAMP() AS unix_time' )->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( 'wp_tests', $row['db_name'] );
		$this->assertSame( '8.0.38-DuckDB', $row['mysql_version'] );
		$this->assertIsFloat( $row['r'] );
		$this->assertGreaterThanOrEqual( 0, $row['r'] );
		$this->assertLessThan( 1, $row['r'] );
		$this->assertIsInt( $row['unix_time'] );
		$this->assertGreaterThan( time() - 60, $row['unix_time'] );
		$this->assertLessThan( time() + 60, $row['unix_time'] );
	}

	public function test_date_format_function_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$row    = $driver->query( "SELECT DATE_FORMAT(DATE '2026-06-26', '%Y-%m-%d') AS formatted_date" )->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( '2026-06-26', $row['formatted_date'] );
	}

	public function test_create_table_insert_update_delete_show_and_describe(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$create = $driver->query(
			"CREATE TABLE `users` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				`name` VARCHAR(100) NOT NULL DEFAULT 'anonymous',
				`visits` INT DEFAULT 0
			) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
		);

		$this->assertSame( 0, $create->rowCount() );
		$this->assertNotEmpty(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'CREATE TABLE "users"' );
				}
			)
		);

			$insert = $driver->query( "INSERT INTO `users` (`name`) VALUES ('Ada'), ('Grace')" );
			$this->assertSame( 2, $insert->rowCount() );
			$this->assertSame( 'INSERT INTO "users"("name") VALUES (\'Ada\'), (\'Grace\')', $this->lastDuckDBQuery( $driver ) );

			$rows = $driver->query( 'SELECT id, name, visits FROM `users` ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'name'   => 'Ada',
					'visits' => 0,
				),
				array(
					'id'     => 2,
					'name'   => 'Grace',
					'visits' => 0,
				),
			),
			$rows
		);

		$update = $driver->query( "UPDATE `users` SET `visits` = 3 WHERE `name` = 'Ada'" );
		$this->assertSame( 1, $update->rowCount() );

		$delete = $driver->query( "DELETE FROM `users` WHERE `name` = 'Grace'" );
		$this->assertSame( 1, $delete->rowCount() );

		$remaining = $driver->query( 'SELECT name, visits FROM `users` ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'name'   => 'Ada',
					'visits' => 3,
				),
			),
			$remaining
		);

		$this->assertSame(
			array( array( 'Tables_in_wp' => 'users' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$describe = $driver->query( 'DESCRIBE `users`' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				'Field'   => 'id',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			),
			$describe[0]
		);
		$this->assertSame( 'name', $describe[1]['Field'] );
		$this->assertSame( 'NO', $describe[1]['Null'] );
		$this->assertSame( 'anonymous', $describe[1]['Default'] );
	}

	public function test_sql_transaction_statements_update_connection_state_and_query_log(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE tx_state (id INT)' );

		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$driver->query( 'BEGIN' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'BEGIN TRANSACTION' ), $driver->get_last_duckdb_queries() );

		$driver->query( 'INSERT INTO tx_state (id) VALUES (1)' );
		$driver->query( 'BEGIN' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'COMMIT', 'BEGIN TRANSACTION' ), $driver->get_last_duckdb_queries() );

		$driver->query( 'INSERT INTO tx_state (id) VALUES (2)' );
		$driver->query( 'ROLLBACK' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'ROLLBACK' ), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array( array( 'id' => 1 ) ),
			$driver->query( 'SELECT id FROM tx_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'COMMIT' );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_update_delete_alias_order_limit_are_rewritten(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE items (id INT, name VARCHAR(20), hits INT)' );
		$driver->query( "INSERT INTO items VALUES (1, 'b', 1), (2, 'a', 2), (3, 'c', 3)" );

		$update_ordered = $driver->query( 'UPDATE items SET hits = 9 ORDER BY name LIMIT 1' );
		$this->assertSame( 1, $update_ordered->rowCount() );

		$update_alias = $driver->query( "UPDATE items AS i SET i.hits = 7 WHERE i.name = 'b' LIMIT 1" );
		$this->assertSame( 1, $update_alias->rowCount() );

		$update_qualified = $driver->query( 'UPDATE wp.items SET hits = 6 WHERE id = 3' );
		$this->assertSame( 1, $update_qualified->rowCount() );

		$update_limit_zero = $driver->query( 'UPDATE items SET hits = 5 LIMIT 0' );
		$this->assertSame( 0, $update_limit_zero->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'b',
					'hits' => 7,
				),
				array(
					'id'   => 2,
					'name' => 'a',
					'hits' => 9,
				),
				array(
					'id'   => 3,
					'name' => 'c',
					'hits' => 6,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$delete_alias = $driver->query( "DELETE FROM items AS i WHERE i.name = 'b' LIMIT 1" );
		$this->assertSame( 1, $delete_alias->rowCount() );

		$delete_ordered = $driver->query( 'DELETE FROM wp.items ORDER BY name LIMIT 1' );
		$this->assertSame( 1, $delete_ordered->rowCount() );

		$delete_limit_zero = $driver->query( 'DELETE FROM items LIMIT 0' );
		$this->assertSame( 0, $delete_limit_zero->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 3,
					'name' => 'c',
					'hits' => 6,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_join_and_comma_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE posts (id INT, status VARCHAR(20), score INT)' );
		$driver->query( 'CREATE TABLE post_updates (post_id INT, new_status VARCHAR(20), bump INT, flag VARCHAR(20))' );
		$driver->query( "INSERT INTO posts VALUES (1, 'draft', 0), (2, 'draft', 0), (3, 'publish', 5)" );
		$driver->query(
			"INSERT INTO post_updates VALUES
			(1, 'publish', 10, 'apply'),
			(2, 'private', 20, 'skip'),
			(3, 'archive', 30, 'apply')"
		);

		$joined = $driver->query(
			"UPDATE posts p
			JOIN post_updates u ON u.post_id = p.id
			SET p.status = u.new_status, p.score = p.score + u.bump
			WHERE u.flag = 'apply'"
		);
		$this->assertSame( 2, $joined->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'status' => 'publish',
					'score'  => 10,
				),
				array(
					'id'     => 2,
					'status' => 'draft',
					'score'  => 0,
				),
				array(
					'id'     => 3,
					'status' => 'archive',
					'score'  => 35,
				),
			),
			$driver->query( 'SELECT id, status, score FROM posts ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$comma = $driver->query(
			"UPDATE posts p, post_updates u
			SET p.status = 'queued'
			WHERE p.id = u.post_id AND u.flag = 'skip'"
		);
		$this->assertSame( 1, $comma->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'status' => 'publish',
					'score'  => 10,
				),
				array(
					'id'     => 2,
					'status' => 'queued',
					'score'  => 0,
				),
				array(
					'id'     => 3,
					'status' => 'archive',
					'score'  => 35,
				),
			),
			$driver->query( 'SELECT id, status, score FROM posts ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_derived_table_claim_query(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
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
			)"
		);
		$driver->query(
			"INSERT INTO wp_actionscheduler_actions
				(action_id, status, scheduled_date_gmt, priority, attempts, claim_id)
			VALUES
				(1, 'pending', '2025-09-03 12:00:00', 10, 0, 0),
				(2, 'pending', '2025-09-03 12:10:00', 5, 0, 0),
				(3, 'pending', '2025-09-03 12:20:00', 15, 0, 0),
				(4, 'pending', '2025-09-03 12:00:00', 1, 0, 9)"
		);

		$claimed = $driver->query(
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
				last_attempt_local = '2025-09-03 12:23:55'"
		);

		$this->assertSame( 2, $claimed->rowCount() );
		$this->assertSame(
			array(
				array(
					'action_id'        => 1,
					'claim_id'         => 37,
					'last_attempt_gmt' => '2025-09-03 12:23:55',
				),
				array(
					'action_id'        => 2,
					'claim_id'         => 37,
					'last_attempt_gmt' => '2025-09-03 12:23:55',
				),
				array(
					'action_id'        => 3,
					'claim_id'         => 0,
					'last_attempt_gmt' => null,
				),
				array(
					'action_id'        => 4,
					'claim_id'         => 9,
					'last_attempt_gmt' => null,
				),
			),
			$driver->query(
				'SELECT action_id, claim_id, last_attempt_gmt
				FROM wp_actionscheduler_actions
				ORDER BY action_id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rejects_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'x'), (3, 'z')" );

		foreach (
			array(
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b ON a.id = b.id SET a.note = 'target', b.note = 'source'",
					'message' => 'UPDATE statement modifying multiple tables is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a, t2 b SET a.note = 'target', b.note = 'source' WHERE a.id = b.id",
					'message' => 'UPDATE statement modifying multiple tables is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a LEFT JOIN t2 b ON a.id = b.id SET a.note = 'target'",
					'message' => 'Only comma joins and INNER JOIN ... ON are supported',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b ON a.id = b.id SET a.note = 'target' ORDER BY a.id LIMIT 1",
					'message' => 'Joined UPDATE with ORDER BY or LIMIT is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a, information_schema.tables it SET a.note = 'target'",
					'message' => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				),
			) as $rejection
		) {
			try {
				$driver->query( $rejection['sql'] );
				$this->fail( 'Expected joined UPDATE rejection for SQL: ' . $rejection['sql'] );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $rejection['message'], $e->getMessage() );
			}
		}

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'note' => 'a',
				),
				array(
					'id'   => 2,
					'note' => 'b',
				),
			),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_escaped_like_predicates_use_mysql_backslash_semantics(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE options (option_name VARCHAR(100))' );
		$driver->query(
			"INSERT INTO options VALUES
			('_transient_tag4'),
			('_transient_timeout_tag4'),
			('_site_transient_tag1'),
			('x_transient_tag4')"
		);

		$rows = $driver->query(
			"SELECT option_name
			FROM options
			WHERE option_name LIKE '\_transient\_%'
			AND option_name NOT LIKE '\_transient\_timeout_%'
			ORDER BY option_name"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'option_name' => '_transient_tag4' ) ), $rows );
		$this->assertStringContainsString( " ESCAPE '\\'", $this->lastDuckDBQuery( $driver ) );
	}

	public function test_multi_table_delete_removes_expired_transient_alias_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE wp_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_options (option_name, option_value, autoload) VALUES
			('_transient_tag4', 'tag4', 'no'),
			('_transient_timeout_tag4', '1', 'no'),
			('_transient_tag5', 'tag5', 'no'),
			('_transient_timeout_tag5', '9999999999', 'no'),
			('_site_transient_tag1', 'tag1', 'no'),
			('_site_transient_timeout_tag1', '1', 'no'),
			('rss_1', 'rss', 'yes')"
		);

		$delete = $driver->query(
			"DELETE a, b FROM wp_options a, wp_options b
			WHERE a.option_name LIKE '\_transient\_%'
			AND a.option_name NOT LIKE '\_transient\_timeout_%'
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < UNIX_TIMESTAMP()"
		);

		$this->assertSame( 2, $delete->rowCount() );
		$this->assertSame(
			array(
				array( 'option_name' => '_transient_tag5' ),
				array( 'option_name' => '_transient_timeout_tag5' ),
				array( 'option_name' => '_site_transient_tag1' ),
				array( 'option_name' => '_site_transient_timeout_tag1' ),
				array( 'option_name' => 'rss_1' ),
			),
			$driver->query( 'SELECT option_name FROM wp_options ORDER BY option_id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_multi_table_delete_using_form_and_single_target_are_rewritten(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'x'), (3, 'z'), (4, 'other')" );

		$using_delete = $driver->query( 'DELETE FROM a, b USING t1 a, t2 b WHERE a.id = b.id AND a.id = 1' );
		$this->assertSame( 2, $using_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'note' => 'b',
				),
				array(
					'id'   => 3,
					'note' => 'c',
				),
			),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 3,
					'note' => 'z',
				),
				array(
					'id'   => 4,
					'note' => 'other',
				),
			),
			$driver->query( 'SELECT id, note FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$single_target_delete = $driver->query( 'DELETE a FROM t1 a, t2 b WHERE a.id = b.id' );
		$this->assertSame( 1, $single_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'note' => 'b',
				),
			),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 3,
					'note' => 'z',
				),
				array(
					'id'   => 4,
					'note' => 'other',
				),
			),
			$driver->query( 'SELECT id, note FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_multi_table_delete_rejects_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT)' );
		$driver->query( 'CREATE TABLE has_rowid (rowid INT, id INT)' );

		foreach (
			array(
				array(
					'sql'     => 'DELETE a FROM t1 a JOIN t2 b ON a.id = b.id',
					'message' => 'Joined table references in multi-table DELETE are not supported yet',
				),
				array(
					'sql'     => 'DELETE a.* FROM t1 a',
					'message' => 'DELETE target wildcards are not supported',
				),
				array(
					'sql'     => 'DELETE t FROM information_schema.tables t',
					'message' => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				),
				array(
					'sql'     => 'DELETE r FROM has_rowid r WHERE r.id = 1',
					'message' => 'ORDER BY/LIMIT rewrites require a table without a user-defined rowid column.',
				),
			) as $rejection
		) {
			try {
				$driver->query( $rejection['sql'] );
				$this->fail( 'Expected multi-table DELETE rejection for SQL: ' . $rejection['sql'] );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $rejection['message'], $e->getMessage() );
			}
		}
	}

	public function test_ordered_limited_dml_rejects_user_defined_rowid_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE items (rowid INT, name VARCHAR(20), hits INT)' );
		$driver->query( "INSERT INTO items VALUES (10, 'b', 1), (20, 'a', 2)" );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'ORDER BY/LIMIT rewrites require a table without a user-defined rowid column.' );
		$driver->query( 'UPDATE items SET hits = 9 ORDER BY name LIMIT 1' );
	}

	public function test_show_columns_and_full_fields_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE `metadata` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				`val1` INT DEFAULT NULL,
				`val2` INT NOT NULL DEFAULT 0,
				`title` VARCHAR(100) NOT NULL DEFAULT 'untitled' COMMENT 'DuckDB does not persist this yet'
			)"
		);

		$this->assertSame(
			array(
				array(
					'Field'   => 'val1',
					'Type'    => 'int',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'val2',
					'Type'    => 'int',
					'Null'    => 'NO',
					'Key'     => '',
					'Default' => '0',
					'Extra'   => '',
				),
			),
			$driver->query( "SHOW COLUMNS FROM `metadata` LIKE 'val_'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Field'      => 'title',
					'Type'       => 'varchar(100)',
					'Collation'  => 'utf8mb4_0900_ai_ci',
					'Null'       => 'NO',
					'Key'        => '',
					'Default'    => 'untitled',
					'Extra'      => '',
					'Privileges' => 'select,insert,update,references',
					'Comment'    => 'DuckDB does not persist this yet',
				),
			),
			$driver->query( "SHOW FULL FIELDS IN `metadata` LIKE 'title'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_regexp_predicates_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE options (option_name VARCHAR(100))' );
		$driver->query( "INSERT INTO options VALUES ('rss_123'), ('transient')" );

		$regexp_rows = $driver->query( "SELECT option_name FROM options WHERE option_name REGEXP '^rss_.+$'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'rss_123' ) ), $regexp_rows );

		$not_regexp_rows = $driver->query( "SELECT option_name FROM options WHERE option_name NOT REGEXP '^rss_.+$'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'transient' ) ), $not_regexp_rows );
	}

	public function test_table_level_primary_key_is_supported(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE memberships (
				user_id BIGINT NOT NULL,
				site_id BIGINT NOT NULL,
				role VARCHAR(20) DEFAULT 'subscriber',
				PRIMARY KEY (user_id, site_id)
			)"
		);

		$driver->query( 'INSERT INTO memberships (user_id, site_id) VALUES (1, 2)' );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$driver->query( 'INSERT INTO memberships (user_id, site_id) VALUES (1, 2)' );
	}

	public function test_replace_values_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE items (
				id INTEGER PRIMARY KEY,
				name VARCHAR(100) NOT NULL DEFAULT '',
				hits INTEGER NOT NULL DEFAULT 0
			)"
		);
		$driver->query( "INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)" );

		$replace = $driver->query( "REPLACE INTO items (id, name, hits) VALUES (1, 'new', 2)" );
		$this->assertSame( 1, $replace->rowCount() );
		$this->assertSame( "INSERT OR REPLACE INTO items(id, name, hits) VALUES (1, 'new', 2)", $this->lastDuckDBQuery( $driver ) );

		$replace_without_into = $driver->query( "REPLACE items (id, name) VALUES (2, 'second')" );
		$this->assertSame( 1, $replace_without_into->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'new',
					'hits' => 2,
				),
				array(
					'id'   => 2,
					'name' => 'second',
					'hits' => 0,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_ignore_values_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100) UNIQUE)' );
		$driver->query( "INSERT INTO items (id, name) VALUES (1, 'first')" );

		$indexes = $driver->query( 'SHOW INDEX FROM items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'PRIMARY', 'name' ), array_column( $indexes, 'Key_name' ) );
		$this->assertSame( 0, (int) $indexes[1]['Non_unique'] );

		$duplicate_primary = $driver->query( "INSERT IGNORE INTO items (id, name) VALUES (1, 'duplicate-id')" );
		$this->assertSame( 0, $duplicate_primary->rowCount() );
		$this->assertSame( "INSERT OR IGNORE INTO items(id, name) VALUES (1, 'duplicate-id')", $this->lastDuckDBQuery( $driver ) );

		$duplicate_unique = $driver->query( "INSERT IGNORE items (id, name) VALUES (2, 'first')" );
		$this->assertSame( 0, $duplicate_unique->rowCount() );

		$inserted = $driver->query( "INSERT IGNORE INTO items (id, name) VALUES (3, 'third')" );
		$this->assertSame( 1, $inserted->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'first',
				),
				array(
					'id'   => 3,
					'name' => 'third',
				),
			),
			$driver->query( 'SELECT id, name FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE items (
				id INTEGER PRIMARY KEY,
				name VARCHAR(100) NOT NULL DEFAULT \'\',
				hits INTEGER NOT NULL DEFAULT 0
			)'
		);

		$inserted = $driver->query( "INSERT INTO items SET id = 1, name = 'first', hits = 2" );
		$this->assertSame( 1, $inserted->rowCount() );
		$this->assertSame( "INSERT INTO items (id, name, hits) VALUES (1, 'first', 2)", $this->lastDuckDBQuery( $driver ) );

		$inserted_without_into = $driver->query( "INSERT items SET id = 2, name = 'second'" );
		$this->assertSame( 1, $inserted_without_into->rowCount() );

		$ignored = $driver->query( "INSERT IGNORE items SET id = 2, name = 'duplicate'" );
		$this->assertSame( 0, $ignored->rowCount() );
		$this->assertSame( "INSERT OR IGNORE INTO items (id, name) VALUES (2, 'duplicate')", $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'first',
					'hits' => 2,
				),
				array(
					'id'   => 2,
					'name' => 'second',
					'hits' => 0,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_select_and_replace_select_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE source_items (id INTEGER, name VARCHAR(100))' );
		$driver->query( 'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100))' );
		$driver->query( "INSERT INTO source_items VALUES (1, 'first'), (2, 'second')" );

		$inserted = $driver->query( 'INSERT INTO items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertSame( 1, $inserted->rowCount() );
		$this->assertSame( 'INSERT INTO items(id, name) SELECT id, name FROM source_items WHERE id = 1', $this->lastDuckDBQuery( $driver ) );

		$inserted_without_target_columns = $driver->query( 'INSERT INTO items SELECT id, name FROM source_items WHERE id = 2' );
		$this->assertSame( 1, $inserted_without_target_columns->rowCount() );
		$this->assertSame( 'INSERT INTO items SELECT id, name FROM source_items WHERE id = 2', $this->lastDuckDBQuery( $driver ) );

		$ignored = $driver->query( 'INSERT IGNORE items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertSame( 0, $ignored->rowCount() );
		$this->assertSame( 'INSERT OR IGNORE INTO items(id, name) SELECT id, name FROM source_items WHERE id = 1', $this->lastDuckDBQuery( $driver ) );

		$inserted_from_dual = $driver->query( "INSERT items (id, name) SELECT 3, 'third' FROM DUAL WHERE (SELECT NULL FROM DUAL) IS NULL" );
		$this->assertSame( 1, $inserted_from_dual->rowCount() );
		$this->assertSame( "INSERT INTO items(id, name) SELECT 3, 'third' WHERE (SELECT NULL) IS NULL", $this->lastDuckDBQuery( $driver ) );

		$replaced_from_dual = $driver->query( "REPLACE INTO items (id, name) SELECT 1, 'replaced' FROM DUAL" );
		$this->assertSame( 1, $replaced_from_dual->rowCount() );
		$this->assertSame( "INSERT OR REPLACE INTO items(id, name) SELECT 1, 'replaced'", $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'replaced',
				),
				array(
					'id'   => 2,
					'name' => 'second',
				),
				array(
					'id'   => 3,
					'name' => 'third',
				),
			),
			$driver->query( 'SELECT id, name FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_id_tracks_generated_auto_increment_values(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE auto_items (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) UNIQUE
			)'
		);
		$driver->query( 'CREATE TABLE source_names (name VARCHAR(100))' );
		$driver->query(
			'CREATE TABLE replace_items (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100)
			)'
		);
		$driver->query( "INSERT INTO source_names VALUES ('fifth'), ('sixth')" );

		$driver->query( "INSERT INTO auto_items (name) VALUES ('first')" );
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->query( "INSERT INTO auto_items (name) VALUES ('second'), ('third')" );
		$this->assertSame( 3, $driver->get_insert_id() );

		$driver->query( "INSERT auto_items SET name = 'fourth'" );
		$this->assertSame( 4, $driver->get_insert_id() );

		$driver->query( 'INSERT INTO auto_items (name) SELECT name FROM source_names ORDER BY name' );
		$this->assertSame( 6, $driver->get_insert_id() );

		$driver->query( "REPLACE INTO replace_items (name) VALUES ('replacement')" );
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->query( "REPLACE INTO replace_items (id, name) VALUES (42, 'explicit-replacement')" );
		$this->assertSame( 42, $driver->get_insert_id() );

		$driver->query( "INSERT INTO auto_items (id, name) VALUES (42, 'explicit')" );
		$this->assertSame( 42, $driver->get_insert_id() );

		$driver->query( "INSERT IGNORE INTO auto_items (name) VALUES ('first')" );
		$this->assertSame( 0, $driver->get_insert_id() );

		try {
			$driver->query( "INSERT INTO auto_items (id, name) VALUES (42, 'duplicate-id')" );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame( 0, $driver->get_insert_id() );
			return;
		}

		$this->fail( 'Expected duplicate insert to fail.' );
	}

	public function test_insert_on_duplicate_key_update_values_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE items (
				id INTEGER PRIMARY KEY,
				name VARCHAR(100) UNIQUE,
				hits INTEGER NOT NULL DEFAULT 0
			)'
		);
		$driver->query( "INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)" );

		$duplicate_primary = $driver->query(
			"INSERT INTO items (id, name, hits) VALUES (1, 'renamed', 7)
			ON DUPLICATE KEY UPDATE name = VALUES(name), hits = VALUES(hits)"
		);
		$this->assertSame( 1, $duplicate_primary->rowCount() );
		$this->assertSame(
			'INSERT INTO items(id, name, hits) VALUES (1, \'renamed\', 7) ON CONFLICT ("id") DO UPDATE SET name = excluded."name", hits = excluded."hits"',
			$this->lastDuckDBQuery( $driver )
		);

		$duplicate_unique = $driver->query(
			"INSERT INTO items (id, name, hits) VALUES (2, 'renamed', 11)
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)"
		);
		$this->assertSame( 1, $duplicate_unique->rowCount() );
		$this->assertSame(
			'INSERT INTO items(id, name, hits) VALUES (2, \'renamed\', 11) ON CONFLICT ("name") DO UPDATE SET hits = hits + excluded."hits"',
			$this->lastDuckDBQuery( $driver )
		);

		$inserted = $driver->query(
			"INSERT INTO items (id, name, hits) VALUES (3, 'third', 3)
			ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
		);
		$this->assertSame( 1, $inserted->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'renamed',
					'hits' => 18,
				),
				array(
					'id'   => 3,
					'name' => 'third',
					'hits' => 3,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_case_insensitive_unique_conflicts_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE ci_items (
				id INTEGER PRIMARY KEY,
				name VARCHAR(20) NOT NULL DEFAULT '',
				payload VARCHAR(20),
				UNIQUE KEY name (name)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$this->assertStringContainsString( 'COLLATE NOCASE', $driver->get_last_duckdb_queries()[0] );

		$driver->query( "INSERT INTO ci_items (id, name, payload) VALUES (1, 'first', 'a')" );

		try {
			$driver->query( "INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'duplicate')" );
			$this->fail( 'Expected case-insensitive duplicate INSERT to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'UNIQUE constraint failed', $e->getMessage() );
		}

		$ignored = $driver->query( "INSERT IGNORE INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'ignored')" );
		$this->assertSame( 0, $ignored->rowCount() );

		$updated = $driver->query(
			"INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE name = VALUES(name), payload = VALUES(payload)"
		);
		$this->assertSame( 1, $updated->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'name'    => 'FIRST',
					'payload' => 'updated',
				),
			),
			$driver->query( 'SELECT id, name, payload FROM ci_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$replaced = $driver->query( "REPLACE INTO ci_items (id, name, payload) VALUES (2, 'first', 'replaced')" );
		$this->assertSame( 1, $replaced->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 2,
					'name'    => 'first',
					'payload' => 'replaced',
				),
			),
			$driver->query( 'SELECT id, name, payload FROM ci_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_binary_collation_unique_keys_remain_case_sensitive(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE bin_items (
				id INTEGER PRIMARY KEY,
				name VARCHAR(20),
				payload VARCHAR(20),
				UNIQUE KEY name (name)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
		);

		$driver->query( "INSERT INTO bin_items (id, name, payload) VALUES (1, 'first', 'a')" );
		$driver->query( "INSERT INTO bin_items (id, name, payload) VALUES (2, 'FIRST', 'b')" );

		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'name'    => 'first',
					'payload' => 'a',
				),
				array(
					'id'      => 2,
					'name'    => 'FIRST',
					'payload' => 'b',
				),
			),
			$driver->query( 'SELECT id, name, payload FROM bin_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$replaced = $driver->query( "REPLACE INTO bin_items (id, name, payload) VALUES (3, 'first', 'replaced')" );
		$this->assertSame( 1, $replaced->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 2,
					'name'    => 'FIRST',
					'payload' => 'b',
				),
				array(
					'id'      => 3,
					'name'    => 'first',
					'payload' => 'replaced',
				),
			),
			$driver->query( 'SELECT id, name, payload FROM bin_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_style_schema_can_be_created(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		foreach ( $this->wordpressStyleSchemaQueries() as $query ) {
			$driver->query( $query );
		}

		$this->assertSame(
			array(
				array( 'Tables_in_wp' => 'wp_options' ),
				array( 'Tables_in_wp' => 'wp_postmeta' ),
				array( 'Tables_in_wp' => 'wp_posts' ),
				array( 'Tables_in_wp' => 'wp_usermeta' ),
				array( 'Tables_in_wp' => 'wp_users' ),
			),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$options_indexes = $driver->query( 'SHOW INDEX FROM wp_options' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'PRIMARY', 'autoload', 'option_name' ), array_column( $options_indexes, 'Key_name' ) );
		$this->assertSame( array( 0, 1, 0 ), array_map( 'intval', array_column( $options_indexes, 'Non_unique' ) ) );

		$usermeta_indexes = $driver->query( 'SHOW INDEX FROM wp_usermeta' )->fetchAll( PDO::FETCH_ASSOC );
		$meta_key_rows    = array_filter(
			$usermeta_indexes,
			function ( array $row ): bool {
				return 'meta_key' === $row['Key_name'];
			}
		);
		$meta_key_row     = array_values( $meta_key_rows )[0];
		$this->assertSame( 191, $meta_key_row['Sub_part'] );

		$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'https://example.test', 'yes')" );

		try {
			$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'duplicate', 'yes')" );
			$this->fail( 'Expected the wp_options option_name unique key to reject duplicates.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_create_index_statement_updates_show_index_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				post_name VARCHAR(200) NOT NULL DEFAULT '',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post'
			)"
		);

		$result = $driver->query( 'CREATE INDEX post_name ON wp_posts (post_name(191))' );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertNotEmpty(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'CREATE INDEX IF NOT EXISTS "wp_duckdb_idx_' );
				}
			)
		);

		$indexes = $driver->query( 'SHOW INDEX FROM wp_posts' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'PRIMARY', 'post_name' ), array_column( $indexes, 'Key_name' ) );
		$this->assertSame( 191, $indexes[1]['Sub_part'] );
	}

	public function test_alter_table_add_unique_index_is_supported(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://example.test')" );

		$result = $driver->query( 'ALTER TABLE wp_options ADD UNIQUE INDEX option_name (option_name)' );

		$this->assertSame( 0, $result->rowCount() );
		$indexes = $driver->query( 'SHOW INDEX FROM wp_options' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'PRIMARY', 'option_name' ), array_column( $indexes, 'Key_name' ) );
		$this->assertSame( 0, (int) $indexes[1]['Non_unique'] );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'duplicate')" );
	}

	public function test_alter_table_add_column_updates_data_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://example.test')" );

		$result = $driver->query( "ALTER TABLE wp_options ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'" );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertSame(
			array(
				array(
					'option_name' => 'siteurl',
					'autoload'    => 'yes',
				),
			),
			$driver->query( 'SELECT option_name, autoload FROM wp_options' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$describe = $driver->query( 'DESCRIBE wp_options' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'option_id', 'option_name', 'option_value', 'autoload' ), array_column( $describe, 'Field' ) );
		$this->assertSame(
			array(
				'Field'   => 'autoload',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'yes',
				'Extra'   => '',
			),
			$describe[3]
		);

		$full = $driver->query( "SHOW FULL COLUMNS FROM wp_options LIKE 'autoload'" )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'utf8mb4_0900_ai_ci', $full['Collation'] );
	}

	public function test_alter_table_add_column_without_column_keyword_and_position_hints(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE items (id INT, name VARCHAR(50))' );
		$driver->query( "INSERT INTO items VALUES (1, 'alpha')" );

		$driver->query( 'ALTER TABLE items ADD notes LONGTEXT NULL AFTER id' );
		$driver->query( "ALTER TABLE items ADD COLUMN position_hint VARCHAR(20) DEFAULT 'tail' FIRST" );

		$row = $driver->query( 'SELECT id, name, notes, position_hint FROM items' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				'id'            => 1,
				'name'          => 'alpha',
				'notes'         => null,
				'position_hint' => 'tail',
			),
			$row
		);

		$describe = $driver->query( 'SHOW COLUMNS FROM items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'id', 'name', 'notes', 'position_hint' ), array_column( $describe, 'Field' ) );
		$this->assertSame( 'longtext', $describe[2]['Type'] );
		$this->assertSame( 'YES', $describe[2]['Null'] );
		$this->assertSame( 'tail', $describe[3]['Default'] );
	}

	public function test_alter_table_multiple_add_columns_and_index_are_supported(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE items (id INT, slug VARCHAR(50))' );

		$result = $driver->query(
			"ALTER TABLE items
				ADD COLUMN label VARCHAR(50) DEFAULT 'untitled',
				ADD views INT DEFAULT 0,
				ADD UNIQUE INDEX slug_key (slug)"
		);

		$this->assertSame( 0, $result->rowCount() );
		$describe = $driver->query( 'DESCRIBE items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'id', 'slug', 'label', 'views' ), array_column( $describe, 'Field' ) );

		$indexes = $driver->query( 'SHOW INDEX FROM items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'slug_key' ), array_column( $indexes, 'Key_name' ) );
		$this->assertSame( 0, (int) $indexes[0]['Non_unique'] );
	}

	public function test_information_schema_columns_exposes_mysql_shaped_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE metadata (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
				option_value LONGTEXT NOT NULL,
				UNIQUE KEY option_name (option_name)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "ALTER TABLE metadata ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'" );

		$rows = $driver->query(
			"SELECT * FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'TABLE_CATALOG',
				'TABLE_SCHEMA',
				'TABLE_NAME',
				'COLUMN_NAME',
				'ORDINAL_POSITION',
				'COLUMN_DEFAULT',
				'IS_NULLABLE',
				'DATA_TYPE',
				'CHARACTER_MAXIMUM_LENGTH',
				'CHARACTER_OCTET_LENGTH',
				'NUMERIC_PRECISION',
				'NUMERIC_SCALE',
				'DATETIME_PRECISION',
				'CHARACTER_SET_NAME',
				'COLLATION_NAME',
				'COLUMN_TYPE',
				'COLUMN_KEY',
				'EXTRA',
				'PRIVILEGES',
				'COLUMN_COMMENT',
				'GENERATION_EXPRESSION',
				'SRS_ID',
			),
			array_keys( $rows[0] )
		);
		$this->assertSame( array( 'id', 'option_name', 'option_value', 'autoload' ), array_column( $rows, 'COLUMN_NAME' ) );
		$this->assertSame( 'bigint', $rows[0]['DATA_TYPE'] );
		$this->assertSame( 20, $rows[0]['NUMERIC_PRECISION'] );
		$this->assertSame( 'PRI', $rows[0]['COLUMN_KEY'] );
		$this->assertSame( 'auto_increment', $rows[0]['EXTRA'] );
		$this->assertSame( 'varchar', $rows[1]['DATA_TYPE'] );
		$this->assertSame( 191, $rows[1]['CHARACTER_MAXIMUM_LENGTH'] );
		$this->assertSame( 764, $rows[1]['CHARACTER_OCTET_LENGTH'] );
		$this->assertSame( 'utf8mb4', $rows[1]['CHARACTER_SET_NAME'] );
		$this->assertSame( 'utf8mb4_0900_ai_ci', $rows[1]['COLLATION_NAME'] );
		$this->assertSame( 'UNI', $rows[1]['COLUMN_KEY'] );
		$this->assertSame( 'Option name', $rows[1]['COLUMN_COMMENT'] );
		$this->assertSame( 'yes', $rows[3]['COLUMN_DEFAULT'] );

		$aliased = $driver->query(
			"SELECT c.column_name
			FROM information_schema.columns c
			WHERE c.table_schema = 'wp' AND c.table_name = 'metadata'
			ORDER BY c.ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'id', 'option_name', 'option_value', 'autoload' ), array_column( $aliased, 'COLUMN_NAME' ) );

		$internal = $driver->query(
			"SELECT table_name
			FROM information_schema.columns
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal );
	}

	public function test_information_schema_statistics_exposes_mysql_shaped_index_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
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
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$rows = $driver->query(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, NON_UNIQUE, INDEX_SCHEMA, INDEX_NAME,
				SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY, SUB_PART, PACKED, NULLABLE,
				INDEX_TYPE, COMMENT, INDEX_COMMENT, IS_VISIBLE, EXPRESSION
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY index_name, seq_in_index"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'TABLE_CATALOG',
				'TABLE_SCHEMA',
				'TABLE_NAME',
				'NON_UNIQUE',
				'INDEX_SCHEMA',
				'INDEX_NAME',
				'SEQ_IN_INDEX',
				'COLUMN_NAME',
				'COLLATION',
				'CARDINALITY',
				'SUB_PART',
				'PACKED',
				'NULLABLE',
				'INDEX_TYPE',
				'COMMENT',
				'INDEX_COMMENT',
				'IS_VISIBLE',
				'EXPRESSION',
			),
			array_keys( $rows[0] )
		);
		$this->assertSame( array( 'autoload', 'nullable_value', 'option_name', 'option_value_prefix', 'PRIMARY' ), array_column( $rows, 'INDEX_NAME' ) );
		$this->assertSame( array( 'autoload', 'nullable_value', 'option_name', 'option_value', 'id' ), array_column( $rows, 'COLUMN_NAME' ) );
		$this->assertSame( array( 1, 1, 0, 1, 0 ), array_map( 'intval', array_column( $rows, 'NON_UNIQUE' ) ) );
		$this->assertSame( array( null, null, null, 12, null ), array_column( $rows, 'SUB_PART' ) );
		$this->assertSame( array( '', 'YES', '', '', '' ), array_column( $rows, 'NULLABLE' ) );
		$this->assertSame( array( 0, 0, 0, 0, 0 ), array_map( 'intval', array_column( $rows, 'CARDINALITY' ) ) );
		$this->assertSame( array( 'BTREE', 'BTREE', 'BTREE', 'BTREE', 'BTREE' ), array_column( $rows, 'INDEX_TYPE' ) );
		$this->assertSame( array( 'YES', 'YES', 'YES', 'YES', 'YES' ), array_column( $rows, 'IS_VISIBLE' ) );

		$aliased = $driver->query(
			"SELECT s.INDEX_NAME, s.COLUMN_NAME, s.SUB_PART
			FROM information_schema.statistics s
			WHERE s.TABLE_SCHEMA = 'wp' AND s.TABLE_NAME = 'metadata'
			ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'autoload', 'nullable_value', 'option_name', 'option_value_prefix', 'PRIMARY' ), array_column( $aliased, 'INDEX_NAME' ) );

		$quoted = $driver->query(
			"SELECT `INDEX_NAME`, `COLLATION`, `COMMENT`
			FROM information_schema.statistics
			WHERE `TABLE_SCHEMA` = 'wp' AND `TABLE_NAME` = 'metadata'
			ORDER BY `INDEX_NAME`, `SEQ_IN_INDEX`"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( 'A', $quoted[0]['COLLATION'] );
		$this->assertSame( '', $quoted[0]['COMMENT'] );

		$internal = $driver->query(
			"SELECT table_name
			FROM information_schema.statistics
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal );
	}

	public function test_information_schema_constraints_expose_mysql_shaped_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$table_constraints_count = $driver->query(
			"SELECT COUNT(*) AS count
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'"
		)->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $table_constraints_count['count'] );

		$key_column_usage_count = $driver->query(
			"SELECT COUNT(*) AS count
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'"
		)->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $key_column_usage_count['count'] );

		$driver->query( 'CREATE TABLE empty_table (id INT, note TEXT)' );
		$driver->query(
			"CREATE TABLE metadata (
				site_id BIGINT(20) UNSIGNED NOT NULL,
				option_id BIGINT(20) UNSIGNED NOT NULL,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				payload LONGTEXT,
				PRIMARY KEY (site_id, option_id),
				UNIQUE KEY unique_site_option (site_id, option_name),
				KEY payload_prefix (payload(12))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$empty_constraints = $driver->query(
			"SELECT TABLE_NAME, CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'empty_table'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $empty_constraints );

		$constraints = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA,
				TABLE_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'CONSTRAINT_CATALOG',
				'CONSTRAINT_SCHEMA',
				'CONSTRAINT_NAME',
				'TABLE_SCHEMA',
				'TABLE_NAME',
				'CONSTRAINT_TYPE',
				'ENFORCED',
			),
			array_keys( $constraints[0] )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'PRIMARY',
					'TABLE_SCHEMA'       => 'wp',
					'TABLE_NAME'         => 'metadata',
					'CONSTRAINT_TYPE'    => 'PRIMARY KEY',
					'ENFORCED'           => 'YES',
				),
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'unique_site_option',
					'TABLE_SCHEMA'       => 'wp',
					'TABLE_NAME'         => 'metadata',
					'CONSTRAINT_TYPE'    => 'UNIQUE',
					'ENFORCED'           => 'YES',
				),
			),
			$constraints
		);

		$usage = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
				TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name, ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'CONSTRAINT_CATALOG',
				'CONSTRAINT_SCHEMA',
				'CONSTRAINT_NAME',
				'TABLE_CATALOG',
				'TABLE_SCHEMA',
				'TABLE_NAME',
				'COLUMN_NAME',
				'ORDINAL_POSITION',
				'POSITION_IN_UNIQUE_CONSTRAINT',
				'REFERENCED_TABLE_SCHEMA',
				'REFERENCED_TABLE_NAME',
				'REFERENCED_COLUMN_NAME',
			),
			array_keys( $usage[0] )
		);
		$this->assertSame( array( 'PRIMARY', 'PRIMARY', 'unique_site_option', 'unique_site_option' ), array_column( $usage, 'CONSTRAINT_NAME' ) );
		$this->assertSame( array( 'site_id', 'option_id', 'site_id', 'option_name' ), array_column( $usage, 'COLUMN_NAME' ) );
		$this->assertSame( array( 1, 2, 1, 2 ), array_map( 'intval', array_column( $usage, 'ORDINAL_POSITION' ) ) );
		$this->assertSame( array( null, null, null, null ), array_column( $usage, 'POSITION_IN_UNIQUE_CONSTRAINT' ) );
		$this->assertSame( array( 'wp', 'wp', 'wp', 'wp' ), array_column( $usage, 'REFERENCED_TABLE_SCHEMA' ) );
		$this->assertSame( array( null, null, null, null ), array_column( $usage, 'REFERENCED_TABLE_NAME' ) );
		$this->assertSame( array( null, null, null, null ), array_column( $usage, 'REFERENCED_COLUMN_NAME' ) );

		$joined = $driver->query(
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
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'name' => 'PRIMARY',
					'type' => 'PRIMARY KEY',
					'col'  => 'site_id',
					'pos'  => 1,
				),
				array(
					'name' => 'PRIMARY',
					'type' => 'PRIMARY KEY',
					'col'  => 'option_id',
					'pos'  => 2,
				),
				array(
					'name' => 'unique_site_option',
					'type' => 'UNIQUE',
					'col'  => 'site_id',
					'pos'  => 1,
				),
				array(
					'name' => 'unique_site_option',
					'type' => 'UNIQUE',
					'col'  => 'option_name',
					'pos'  => 2,
				),
			),
			$joined
		);

		$uppercase = $driver->query(
			"SELECT tc.CONSTRAINT_NAME, k.COLUMN_NAME
			FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS tc
			JOIN information_schema.key_column_usage AS k
				ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
				AND k.TABLE_SCHEMA = tc.TABLE_SCHEMA
				AND k.TABLE_NAME = tc.TABLE_NAME
			WHERE tc.TABLE_SCHEMA = 'wp' AND tc.TABLE_NAME = 'metadata'
			ORDER BY tc.CONSTRAINT_NAME, k.ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'site_id', 'option_id', 'site_id', 'option_name' ), array_column( $uppercase, 'COLUMN_NAME' ) );

		$non_unique = $driver->query(
			"SELECT CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND constraint_name = 'payload_prefix'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $non_unique );

		$internal_constraints = $driver->query(
			"SELECT table_name
			FROM information_schema.table_constraints
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal_constraints );

		$internal_usage = $driver->query(
			"SELECT table_name
			FROM information_schema.key_column_usage
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal_usage );
	}

	public function test_information_schema_tables_exposes_mysql_shaped_table_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE metadata (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'"
		);
		$driver->query( 'CREATE TABLE plain (id INT, name TEXT)' );
		$driver->query(
			"INSERT INTO metadata (option_name, option_value)
			VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')"
		);

		$rows = $driver->query(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, VERSION,
				ROW_FORMAT, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, MAX_DATA_LENGTH,
				INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME,
				CHECK_TIME, TABLE_COLLATION, CHECKSUM, CREATE_OPTIONS, TABLE_COMMENT
			FROM information_schema.tables
			WHERE table_schema = 'wp'
			ORDER BY table_name"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'TABLE_CATALOG',
				'TABLE_SCHEMA',
				'TABLE_NAME',
				'TABLE_TYPE',
				'ENGINE',
				'VERSION',
				'ROW_FORMAT',
				'TABLE_ROWS',
				'AVG_ROW_LENGTH',
				'DATA_LENGTH',
				'MAX_DATA_LENGTH',
				'INDEX_LENGTH',
				'DATA_FREE',
				'AUTO_INCREMENT',
				'CREATE_TIME',
				'UPDATE_TIME',
				'CHECK_TIME',
				'TABLE_COLLATION',
				'CHECKSUM',
				'CREATE_OPTIONS',
				'TABLE_COMMENT',
			),
			array_keys( $rows[0] )
		);
		$this->assertSame( array( 'metadata', 'plain' ), array_column( $rows, 'TABLE_NAME' ) );
		$this->assertSame( 'MyISAM', $rows[0]['ENGINE'] );
		$this->assertSame( 'Fixed', $rows[0]['ROW_FORMAT'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $rows[0]['TABLE_COLLATION'] );
		$this->assertSame( 'Options table', $rows[0]['TABLE_COMMENT'] );
		$this->assertSame( 3, $rows[0]['AUTO_INCREMENT'] );
		$this->assertRegExp( '/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[0]['CREATE_TIME'] );
		$this->assertSame( 'InnoDB', $rows[1]['ENGINE'] );
		$this->assertSame( 'Dynamic', $rows[1]['ROW_FORMAT'] );
		$this->assertSame( null, $rows[1]['AUTO_INCREMENT'] );

		$aliased = $driver->query(
			"SELECT t.TABLE_NAME, t.ENGINE, t.`AUTO_INCREMENT`
			FROM information_schema.tables t
			WHERE t.TABLE_SCHEMA = 'wp'
			ORDER BY t.TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'metadata', 'plain' ), array_column( $aliased, 'TABLE_NAME' ) );
		$this->assertSame( 3, $aliased[0]['AUTO_INCREMENT'] );

		$internal = $driver->query(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal );
	}

	public function test_show_table_status_exposes_mysql_shaped_table_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE metadata (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'"
		);
		$driver->query( 'CREATE TABLE plain (id INT, name TEXT)' );
		$driver->query(
			"INSERT INTO metadata (option_name, option_value)
			VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')"
		);

		$rows = $driver->query( 'SHOW TABLE STATUS FROM wp' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
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
				'Create_time',
				'Update_time',
				'Check_time',
				'Collation',
				'Checksum',
				'Create_options',
				'Comment',
			),
			array_keys( $rows[0] )
		);
		$this->assertSame( array( 'metadata', 'plain' ), array_column( $rows, 'Name' ) );
		$this->assertSame( 'MyISAM', $rows[0]['Engine'] );
		$this->assertSame( 10, $rows[0]['Version'] );
		$this->assertSame( 'Fixed', $rows[0]['Row_format'] );
		$this->assertSame( 0, $rows[0]['Rows'] );
		$this->assertSame( 0, $rows[0]['Avg_row_length'] );
		$this->assertSame( 0, $rows[0]['Data_length'] );
		$this->assertSame( 0, $rows[0]['Max_data_length'] );
		$this->assertSame( 0, $rows[0]['Index_length'] );
		$this->assertSame( 0, $rows[0]['Data_free'] );
		$this->assertSame( 3, $rows[0]['Auto_increment'] );
		$this->assertRegExp( '/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[0]['Create_time'] );
		$this->assertSame( null, $rows[0]['Update_time'] );
		$this->assertSame( null, $rows[0]['Check_time'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $rows[0]['Collation'] );
		$this->assertSame( null, $rows[0]['Checksum'] );
		$this->assertSame( '', $rows[0]['Create_options'] );
		$this->assertSame( 'Options table', $rows[0]['Comment'] );
		$this->assertSame( 'InnoDB', $rows[1]['Engine'] );
		$this->assertSame( 'Dynamic', $rows[1]['Row_format'] );
		$this->assertSame( null, $rows[1]['Auto_increment'] );

		$like = $driver->query( "SHOW TABLE STATUS IN wp LIKE 'plain'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'plain' ), array_column( $like, 'Name' ) );

		$auto_increment = $driver->query( 'SHOW TABLE STATUS WHERE `Auto_increment` > 2' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'metadata' ), array_column( $auto_increment, 'Name' ) );

		$without_auto_increment = $driver->query( 'SHOW TABLE STATUS WHERE `Auto_increment` IS NULL' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'plain' ), array_column( $without_auto_increment, 'Name' ) );

		$where_function = $driver->query( "SHOW TABLE STATUS WHERE SUBSTR(Name, 1, 4) = 'meta'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'metadata' ), array_column( $where_function, 'Name' ) );

		$other_database = $driver->query( 'SHOW TABLE STATUS FROM other_database' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $other_database );

		$internal = $driver->query( "SHOW TABLE STATUS LIKE '__wp_duckdb_%'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal );
	}

	public function test_show_create_table_reconstructs_mysql_shaped_ddl(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE metadata (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload),
				KEY option_value_prefix (option_value(12))
			) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'"
		);
		$driver->query(
			"INSERT INTO metadata (option_name, option_value)
			VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')"
		);
		$driver->query(
			"CREATE TABLE composite_pk (
				site_id BIGINT(20) UNSIGNED NOT NULL,
				option_id BIGINT(20) UNSIGNED NOT NULL,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (site_id, option_id),
				UNIQUE KEY unique_site_option (site_id, option_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
		$driver->query( 'CREATE TABLE plain (id INT, name TEXT)' );

		$metadata_rows = $driver->query( 'SHOW CREATE TABLE metadata' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'Table', 'Create Table' ), array_keys( $metadata_rows[0] ) );
		$this->assertSame( 'metadata', $metadata_rows[0]['Table'] );
		$this->assertSame(
			<<<'SQL'
CREATE TABLE `metadata` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '' COMMENT 'Option name',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`),
  KEY `option_value_prefix` (`option_value`(12))
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Options table'
SQL,
			$metadata_rows[0]['Create Table']
		);

		$qualified_rows = $driver->query( 'SHOW CREATE TABLE wp.metadata' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( $metadata_rows, $qualified_rows );

		$composite_rows = $driver->query( 'SHOW CREATE TABLE composite_pk' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			<<<'SQL'
CREATE TABLE `composite_pk` (
  `site_id` bigint(20) unsigned NOT NULL,
  `option_id` bigint(20) unsigned NOT NULL,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY (`site_id`, `option_id`),
  UNIQUE KEY `unique_site_option` (`site_id`, `option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
			$composite_rows[0]['Create Table']
		);

		$plain_rows = $driver->query( 'SHOW CREATE TABLE plain' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			<<<'SQL'
CREATE TABLE `plain` (
  `id` int DEFAULT NULL,
  `name` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
			$plain_rows[0]['Create Table']
		);

		$missing_rows = $driver->query( 'SHOW CREATE TABLE missing' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $missing_rows );

		$other_database_rows = $driver->query( 'SHOW CREATE TABLE other_database.metadata' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $other_database_rows );
	}

	public function test_temporary_table_lifecycle_uses_session_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$create = $driver->query(
			"CREATE TEMPORARY TABLE temp_items (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) NOT NULL DEFAULT '',
				KEY name_key (name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		$this->assertSame( 0, $create->rowCount() );

		$driver->query( "INSERT INTO temp_items (name) VALUES ('first'), ('second')" );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'first',
				),
				array(
					'id'   => 2,
					'name' => 'second',
				),
			),
			$driver->query( 'SELECT id, name FROM temp_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->query( "SHOW TABLE STATUS LIKE 'temp_items'" )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( 'PRIMARY', 'name_key' ),
			array_column( $driver->query( 'SHOW INDEX FROM temp_items' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE temp_items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringStartsWith( 'CREATE TEMPORARY TABLE `temp_items`', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'AUTO_INCREMENT=3', $create_rows[0]['Create Table'] );

		$drop = $driver->query( 'DROP TEMPORARY TABLE temp_items' );
		$this->assertSame( 0, $drop->rowCount() );
		$this->assertSame( array(), $driver->query( 'SHOW CREATE TABLE temp_items' )->fetchAll( PDO::FETCH_ASSOC ) );
	}

	public function test_temporary_table_takes_precedence_over_persistent_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE t (a INT, INDEX ia(a))' );
		$driver->query( 'INSERT INTO t VALUES (1)' );
		$driver->query( 'CREATE TEMPORARY TABLE t (b INT, INDEX ib(b))' );
		$driver->query( 'INSERT INTO t VALUES (2)' );

		$this->assertSame(
			array( array( 'b' => 2 ) ),
			$driver->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'b' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'b' ),
			array_column( $driver->query( 'DESCRIBE t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'ib' ),
			array_column( $driver->query( 'SHOW INDEX FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertStringStartsWith(
			'CREATE TEMPORARY TABLE `t`',
			$driver->query( 'SHOW CREATE TABLE t' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		$driver->query( 'ALTER TABLE t ADD COLUMN c INT' );
		$this->assertSame(
			array( 'b', 'c' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( array( 'COLUMN_NAME' => 'a' ) ),
			$driver->query(
				"SELECT COLUMN_NAME
				FROM information_schema.columns
				WHERE table_name = 't'
				ORDER BY ORDINAL_POSITION"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 't' ),
			array_column( $driver->query( "SHOW TABLE STATUS LIKE 't'" )->fetchAll( PDO::FETCH_ASSOC ), 'Name' )
		);

		$driver->query( 'DROP TABLE t' );
		$this->assertSame(
			array( 'a' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( array( 'a' => 1 ) ),
			$driver->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_temporary_tables_are_connection_scoped(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-temp-scope-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$first = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$first->query( 'CREATE TABLE persistent_items (id INT)' );
			$first->query( 'CREATE TEMPORARY TABLE session_items (id INT)' );

			$second = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->assertSame(
				array(
					array(
						'Field'   => 'id',
						'Type'    => 'int',
						'Null'    => 'YES',
						'Key'     => '',
						'Default' => null,
						'Extra'   => '',
					),
				),
				$second->query( 'SHOW COLUMNS FROM persistent_items' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->expectException( WP_DuckDB_Driver_Exception::class );
			$this->expectExceptionMessage( 'DuckDB table does not exist: session_items.' );
			$second->query( 'SHOW COLUMNS FROM session_items' );
		} finally {
			@unlink( $path );
		}
	}

	public function test_truncate_table_preserves_schema_and_resets_auto_increment(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( $this->lifecycleTableSql( 'lifecycle_t' ) );
		$driver->query( "INSERT INTO lifecycle_t (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')" );
		$driver->query( 'DELETE FROM lifecycle_t WHERE name = \'b\'' );

		$this->assertSame(
			array( array( 'AUTO_INCREMENT' => 3 ) ),
			$driver->query(
				"SELECT `AUTO_INCREMENT`
				FROM information_schema.tables
				WHERE table_schema = 'wp' AND table_name = 'lifecycle_t'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$truncate = $driver->query( 'TRUNCATE TABLE wp.lifecycle_t' );
		$this->assertSame( 0, $truncate->rowCount() );
		$this->assertSame(
			array( array( 'count' => 0 ) ),
			$driver->query( 'SELECT COUNT(*) AS count FROM lifecycle_t' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'PRIMARY', 'name_unique', 'payload_prefix' ),
			array_column( $driver->query( 'SHOW INDEX FROM lifecycle_t' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array( 'PRI', 'UNI', 'MUL' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM lifecycle_t' )->fetchAll( PDO::FETCH_ASSOC ), 'Key' )
		);
		$this->assertSame(
			array( array( 'AUTO_INCREMENT' => 1 ) ),
			$driver->query(
				"SELECT `AUTO_INCREMENT`
				FROM information_schema.tables
				WHERE table_schema = 'wp' AND table_name = 'lifecycle_t'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE lifecycle_t' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'AUTO_INCREMENT=', $create_rows[0]['Create Table'] );

		$driver->query( "INSERT INTO lifecycle_t (name, payload) VALUES ('z', 'zulu')" );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'z',
				),
			),
			$driver->query( 'SELECT id, name FROM lifecycle_t ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_drop_index_updates_metadata_and_unique_enforcement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( $this->lifecycleTableSql( 'lifecycle_idx' ) );
		$driver->query( "INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'alpha')" );

		try {
			$driver->query( "INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'duplicate')" );
			$this->fail( 'Duplicate insert should fail before dropping the unique index.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		$drop_payload = $driver->query( 'DROP INDEX payload_prefix ON lifecycle_idx' );
		$this->assertSame( 0, $drop_payload->rowCount() );
		$this->assertSame(
			array( 'PRIMARY', 'name_unique' ),
			array_column( $driver->query( 'SHOW INDEX FROM lifecycle_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array(
				'id'      => 'PRI',
				'name'    => 'UNI',
				'payload' => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM lifecycle_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT INDEX_NAME
				FROM information_schema.statistics
				WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx' AND index_name = 'payload_prefix'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$drop_unique = $driver->query( 'ALTER TABLE wp.lifecycle_idx DROP KEY name_unique' );
		$this->assertSame( 0, $drop_unique->rowCount() );
		$this->assertSame(
			array( 'PRIMARY' ),
			array_column( $driver->query( 'SHOW INDEX FROM lifecycle_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array(
				'id'      => 'PRI',
				'name'    => '',
				'payload' => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM lifecycle_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx' AND constraint_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'duplicate')" );
		$this->assertSame(
			array(
				array( 'name' => 'a' ),
				array( 'name' => 'a' ),
			),
			$driver->query( 'SELECT name FROM lifecycle_idx ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE lifecycle_idx' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'payload_prefix', $create_rows[0]['Create Table'] );
		$this->assertStringNotContainsString( 'name_unique', $create_rows[0]['Create Table'] );
	}

	public function test_alter_table_drop_column_updates_data_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE drop_col_meta (
				id INT,
				keep_col VARCHAR(20) DEFAULT 'keep',
				drop_col INT DEFAULT 0,
				tail_col VARCHAR(20),
				KEY keep_idx (keep_col),
				KEY drop_idx (drop_col),
				KEY keep_drop_tail (keep_col, drop_col, tail_col)
			)"
		);
		$driver->query( "INSERT INTO drop_col_meta (id, keep_col, drop_col, tail_col) VALUES (1, 'a', 9, 'z')" );

		$drop = $driver->query( 'ALTER TABLE drop_col_meta DROP COLUMN drop_col' );
		$this->assertSame( 0, $drop->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'keep_col' => 'a',
					'tail_col' => 'z',
				),
			),
			$driver->query( 'SELECT * FROM drop_col_meta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'id', 'keep_col', 'tail_col' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_col_meta' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'id', 'keep_col', 'tail_col' ),
			array_column( $driver->query( 'DESCRIBE drop_col_meta' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'      => 'id',
					'ORDINAL_POSITION' => 1,
				),
				array(
					'COLUMN_NAME'      => 'keep_col',
					'ORDINAL_POSITION' => 2,
				),
				array(
					'COLUMN_NAME'      => 'tail_col',
					'ORDINAL_POSITION' => 4,
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, ORDINAL_POSITION
				FROM information_schema.columns
				WHERE table_schema = 'wp' AND table_name = 'drop_col_meta'
				ORDER BY ORDINAL_POSITION"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE drop_col_meta' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( '`drop_col`', $create_rows[0]['Create Table'] );
		$this->assertStringNotContainsString( 'KEY `drop_idx`', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'KEY `keep_drop_tail` (`keep_col`, `tail_col`)', $create_rows[0]['Create Table'] );
	}

	public function test_alter_table_drop_column_prunes_secondary_indexes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_idx (
				a INT,
				b INT,
				c INT,
				UNIQUE KEY unique_ab (a, b),
				KEY compound (a, b, c),
				KEY only_b (b),
				KEY c_idx (c)
			)'
		);
		$driver->query( 'INSERT INTO drop_col_idx (a, b, c) VALUES (1, 10, 100), (2, 20, 200)' );

		$driver->query( 'ALTER TABLE drop_col_idx DROP b' );

		$index_rows = array_map(
			function ( array $row ): array {
				return array(
					'Key_name'     => $row['Key_name'],
					'Seq_in_index' => $row['Seq_in_index'],
					'Column_name'  => $row['Column_name'],
					'Non_unique'   => $row['Non_unique'],
				);
			},
			$driver->query( 'SHOW INDEX FROM drop_col_idx' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Key_name'     => 'c_idx',
					'Seq_in_index' => 1,
					'Column_name'  => 'c',
					'Non_unique'   => 1,
				),
				array(
					'Key_name'     => 'compound',
					'Seq_in_index' => 1,
					'Column_name'  => 'a',
					'Non_unique'   => 1,
				),
				array(
					'Key_name'     => 'compound',
					'Seq_in_index' => 2,
					'Column_name'  => 'c',
					'Non_unique'   => 1,
				),
				array(
					'Key_name'     => 'unique_ab',
					'Seq_in_index' => 1,
					'Column_name'  => 'a',
					'Non_unique'   => 0,
				),
			),
			$index_rows
		);
		$this->assertSame(
			array(
				'a' => 'UNI',
				'c' => 'MUL',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_col_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT index_name
				FROM information_schema.statistics
				WHERE table_schema = 'wp' AND table_name = 'drop_col_idx' AND index_name = 'only_b'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_drop_multiple_and_mixed_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_multi (
				a INT,
				b INT,
				c INT,
				marker VARCHAR(20)
			)'
		);
		$driver->query( "INSERT INTO drop_col_multi (a, b, c, marker) VALUES (1, 2, 3, 'row')" );

		$driver->query( 'ALTER TABLE drop_col_multi DROP a, DROP COLUMN b' );
		$this->assertSame(
			array( 'c', 'marker' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_col_multi' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);

		$driver->query( 'ALTER TABLE drop_col_multi ADD d INT DEFAULT 9, DROP c' );
		$this->assertSame(
			array( 'marker', 'd' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_col_multi' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'marker' => 'row',
					'd'      => 9,
				),
			),
			$driver->query( 'SELECT marker, d FROM drop_col_multi' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_drop_column_targets_temporary_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE shadow_drop (a INT, b INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE shadow_drop (a INT, b INT, c INT)' );
		$driver->query( 'INSERT INTO shadow_drop (a, b, c) VALUES (3, 4, 5)' );

		$driver->query( 'ALTER TABLE shadow_drop DROP COLUMN b' );
		$this->assertSame(
			array( 'a', 'c' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_drop' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'a' => 3,
					'c' => 5,
				),
			),
			$driver->query( 'SELECT * FROM shadow_drop' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE shadow_drop' );
		$this->assertSame(
			array( 'a', 'b' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_drop' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
	}

	public function test_alter_table_change_column_renames_with_type_default_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE change_col_meta (
				option_name VARCHAR(255),
				option_value LONGTEXT
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query( "INSERT INTO change_col_meta (option_name, option_value) VALUES ('siteurl', 'https://example.test')" );

		$change = $driver->query( "ALTER TABLE change_col_meta CHANGE COLUMN option_name option_key VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option key'" );

		$this->assertSame( 0, $change->rowCount() );
		$this->assertSame(
			array(
				array(
					'option_key'   => 'siteurl',
					'option_value' => 'https://example.test',
				),
			),
			$driver->query( 'SELECT option_key, option_value FROM change_col_meta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'option_key', 'option_value' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM change_col_meta' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);

		$key_column = $driver->query( "SHOW FULL COLUMNS FROM change_col_meta LIKE 'option_key'" )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'varchar(191)', $key_column['Type'] );
		$this->assertSame( 'NO', $key_column['Null'] );
		$this->assertSame( '', $key_column['Default'] );
		$this->assertSame( 'Option key', $key_column['Comment'] );

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'      => 'option_key',
					'ORDINAL_POSITION' => 1,
					'COLUMN_DEFAULT'   => '',
					'IS_NULLABLE'      => 'NO',
					'COLUMN_TYPE'      => 'varchar(191)',
					'COLUMN_COMMENT'   => 'Option key',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, COLUMN_COMMENT
				FROM information_schema.columns
				WHERE table_schema = 'wp' AND table_name = 'change_col_meta' AND column_name = 'option_key'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "INSERT INTO change_col_meta (option_value) VALUES ('defaulted')" );
		$this->assertSame(
			array(
				array( 'option_key' => '' ),
			),
			$driver->query( "SELECT option_key FROM change_col_meta WHERE option_value = 'defaulted'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_change_same_name_and_modify_refresh_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE change_modify_meta (
				option_name VARCHAR(255) DEFAULT '7',
				autoload VARCHAR(10) DEFAULT 'no'
			)"
		);
		$driver->query( "INSERT INTO change_modify_meta (option_name, autoload) VALUES ('7', 'no')" );

		$driver->query( 'ALTER TABLE change_modify_meta CHANGE COLUMN option_name option_name SMALLINT NOT NULL DEFAULT 14' );
		$driver->query( "ALTER TABLE change_modify_meta MODIFY COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes' COMMENT 'Load flag'" );
		$driver->query( 'INSERT INTO change_modify_meta (option_name, autoload) VALUES (DEFAULT, DEFAULT)' );

		$this->assertSame(
			array(
				array(
					'option_name' => 7,
					'autoload'    => 'no',
				),
				array(
					'option_name' => 14,
					'autoload'    => 'yes',
				),
			),
			$driver->query( 'SELECT option_name, autoload FROM change_modify_meta ORDER BY option_name' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$columns = array_column( $driver->query( 'SHOW FULL COLUMNS FROM change_modify_meta' )->fetchAll( PDO::FETCH_ASSOC ), null, 'Field' );
		$this->assertSame( 'smallint', $columns['option_name']['Type'] );
		$this->assertSame( 'NO', $columns['option_name']['Null'] );
		$this->assertSame( '14', $columns['option_name']['Default'] );
		$this->assertSame( 'varchar(20)', $columns['autoload']['Type'] );
		$this->assertSame( 'NO', $columns['autoload']['Null'] );
		$this->assertSame( 'yes', $columns['autoload']['Default'] );
		$this->assertSame( 'Load flag', $columns['autoload']['Comment'] );

		$create_rows = $driver->query( 'SHOW CREATE TABLE change_modify_meta' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( "`option_name` smallint NOT NULL DEFAULT '14'", $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( "`autoload` varchar(20) NOT NULL DEFAULT 'yes' COMMENT 'Load flag'", $create_rows[0]['Create Table'] );
	}

	public function test_alter_table_change_column_rebuilds_indexes_for_renamed_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE change_col_idx (
				name VARCHAR(50) NOT NULL DEFAULT 'mark',
				lastname VARCHAR(50),
				payload INT,
				UNIQUE KEY name (name),
				KEY composite (name, lastname)
			)"
		);
		$driver->query( "INSERT INTO change_col_idx (name, lastname, payload) VALUES ('ada', 'lovelace', 1)" );

		$driver->query( "ALTER TABLE change_col_idx CHANGE name firstname VARCHAR(50) NOT NULL DEFAULT 'mark'" );

		$this->assertSame(
			array(
				array(
					'firstname' => 'ada',
					'lastname'  => 'lovelace',
					'payload'   => 1,
				),
			),
			$driver->query( 'SELECT firstname, lastname, payload FROM change_col_idx' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$index_rows = array_map(
			function ( array $row ): array {
				return array(
					'Key_name'     => $row['Key_name'],
					'Seq_in_index' => $row['Seq_in_index'],
					'Column_name'  => $row['Column_name'],
					'Non_unique'   => $row['Non_unique'],
				);
			},
			$driver->query( 'SHOW INDEX FROM change_col_idx' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Key_name'     => 'composite',
					'Seq_in_index' => 1,
					'Column_name'  => 'firstname',
					'Non_unique'   => 1,
				),
				array(
					'Key_name'     => 'composite',
					'Seq_in_index' => 2,
					'Column_name'  => 'lastname',
					'Non_unique'   => 1,
				),
				array(
					'Key_name'     => 'name',
					'Seq_in_index' => 1,
					'Column_name'  => 'firstname',
					'Non_unique'   => 0,
				),
			),
			$index_rows
		);
		$this->assertSame(
			array(
				'firstname' => 'UNI',
				'lastname'  => '',
				'payload'   => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM change_col_idx' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT index_name
				FROM information_schema.statistics
				WHERE table_schema = 'wp' AND table_name = 'change_col_idx' AND column_name = 'name'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( "INSERT INTO change_col_idx (firstname, lastname, payload) VALUES ('ada', 'duplicate', 2)" );
			$this->fail( 'Duplicate firstname should fail after rebuilding the renamed unique index.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_change_column_targets_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE shadow_change (name VARCHAR(20), payload INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE shadow_change (name VARCHAR(20), payload INT)' );
		$driver->query( "INSERT INTO shadow_change (name, payload) VALUES ('temp', 3)" );

		$driver->query( "ALTER TABLE shadow_change CHANGE COLUMN name temp_name VARCHAR(30) DEFAULT ''" );

		$this->assertSame(
			array( 'temp_name', 'payload' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_change' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'temp_name' => 'temp',
					'payload'   => 3,
				),
			),
			$driver->query( 'SELECT * FROM shadow_change' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE shadow_change' );
		$this->assertSame(
			array( 'name', 'payload' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_change' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
	}

	public function test_alter_table_change_modify_rejects_protected_definitions(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE change_pk (id INT NOT NULL, note VARCHAR(20), PRIMARY KEY (id))' );
		$driver->query( 'CREATE TABLE change_auto (id BIGINT NOT NULL AUTO_INCREMENT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE change_inline_unique (name VARCHAR(20))' );

		foreach (
			array(
				'ALTER TABLE change_pk CHANGE id item_id INT NOT NULL' => 'primary key column requires a table rebuild',
				'ALTER TABLE change_auto MODIFY id BIGINT NOT NULL' => 'AUTO_INCREMENT column requires a table rebuild',
				'ALTER TABLE change_inline_unique MODIFY name VARCHAR(20) UNIQUE' => 'inline UNIQUE is not supported',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected CHANGE/MODIFY protection to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_alter_table_drop_column_rejects_protected_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_col_pk (id INT NOT NULL, keep_col INT, PRIMARY KEY (id))' );
		$driver->query( 'CREATE TABLE drop_col_auto (id BIGINT NOT NULL AUTO_INCREMENT, keep_col INT, KEY id_idx (id))' );
		$driver->query( 'CREATE TABLE drop_col_last (only_col INT)' );

		foreach (
			array(
				'ALTER TABLE drop_col_pk DROP COLUMN id'   => 'primary key column requires a table rebuild',
				'ALTER TABLE drop_col_auto DROP COLUMN id' => 'AUTO_INCREMENT column requires a table rebuild',
				'ALTER TABLE drop_col_last DROP COLUMN only_col' => 'DROP COLUMN cannot remove the last column',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected DROP COLUMN protection to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_drop_table_removes_metadata_and_sequence_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( $this->lifecycleTableSql( 'lifecycle_drop' ) );
		$driver->query( 'CREATE TABLE survivor (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO lifecycle_drop (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')" );

		$drop = $driver->query( 'DROP TABLE wp.lifecycle_drop' );
		$this->assertSame( 0, $drop->rowCount() );
		$this->assertSame(
			array( array( 'Tables_in_wp' => 'survivor' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->query( 'SHOW CREATE TABLE lifecycle_drop' )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->query( 'SHOW INDEX FROM lifecycle_drop' )->fetchAll( PDO::FETCH_ASSOC ) );

		foreach ( array( 'tables', 'columns', 'statistics', 'table_constraints', 'key_column_usage' ) as $table ) {
			$this->assertSame(
				array(),
				$driver->query(
					"SELECT *
					FROM information_schema.{$table}
					WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
				)->fetchAll( PDO::FETCH_ASSOC ),
				'DuckDB metadata was not cleared from information_schema.' . $table
			);
		}

		$driver->query( $this->lifecycleTableSql( 'lifecycle_drop' ) );
		$driver->query( "INSERT INTO lifecycle_drop (name, payload) VALUES ('fresh', 'value')" );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'fresh',
				),
			),
			$driver->query( 'SELECT id, name FROM lifecycle_drop' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_drop_table_multiple_if_exists_and_lifecycle_protections(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE first_table (id INT)' );
		$driver->query( 'CREATE TABLE second_table (id INT)' );
		$driver->query( 'CREATE TABLE protected_target (id INT, KEY id_idx(id))' );

		$driver->query( 'DROP TABLE wp.first_table, second_table' );
		$this->assertSame(
			array( array( 'Tables_in_wp' => 'protected_target' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 0, $driver->query( 'DROP TABLE IF EXISTS missing_table' )->rowCount() );

		foreach (
			array(
				'DROP TABLE information_schema.tables'     => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				'TRUNCATE TABLE __wp_duckdb_column_metadata' => 'Internal DuckDB metadata tables cannot be modified',
				'DROP INDEX `PRIMARY` ON protected_target' => 'Dropping PRIMARY requires a table rebuild',
				'ALTER TABLE protected_target DROP PRIMARY KEY' => 'DROP PRIMARY KEY requires a table rebuild',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected lifecycle protection to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_show_create_table_denies_information_schema_tables(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( "SHOW command denied to user 'duckdb'@'%'" );
		$driver->query( 'SHOW CREATE TABLE information_schema.tables' );
	}

	public function test_unsupported_alter_table_add_column_constraints_throw_driver_exception(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE users (name VARCHAR(100))' );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN PRIMARY KEY is not supported.' );
		$driver->query( 'ALTER TABLE users ADD COLUMN id INT PRIMARY KEY' );
	}

	public function test_unsupported_alter_table_add_auto_increment_throws_driver_exception(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE users (name VARCHAR(100))' );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN AUTO_INCREMENT is not supported.' );
		$driver->query( 'ALTER TABLE users ADD COLUMN id BIGINT AUTO_INCREMENT' );
	}

	public function test_unsupported_alter_table_add_not_null_without_default_on_non_empty_table_throws_driver_exception(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE users (name VARCHAR(100))' );
		$driver->query( "INSERT INTO users VALUES ('Ada')" );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'Unsupported ALTER TABLE statement in DuckDB driver. ADD COLUMN NOT NULL requires a DEFAULT for non-empty tables.' );
		$driver->query( 'ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL' );
	}

	private function lastDuckDBQuery( WP_DuckDB_Driver $driver ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$queries = $driver->get_last_duckdb_queries();
		return $queries[ count( $queries ) - 1 ];
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

	/**
	 * WordPress-style schema statements that exercise core DDL shapes.
	 *
	 * @return string[]
	 */
	private function wordpressStyleSchemaQueries(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			"CREATE TABLE wp_users (
				ID bigint(20) unsigned NOT NULL auto_increment,
				user_login varchar(60) NOT NULL default '',
				user_pass varchar(255) NOT NULL default '',
				user_nicename varchar(50) NOT NULL default '',
				user_email varchar(100) NOT NULL default '',
				user_url varchar(100) NOT NULL default '',
				user_registered datetime NOT NULL default '0000-00-00 00:00:00',
				user_activation_key varchar(255) NOT NULL default '',
				user_status int(11) NOT NULL default '0',
				display_name varchar(250) NOT NULL default '',
				PRIMARY KEY  (ID),
				KEY user_login_key (user_login),
				KEY user_nicename (user_nicename),
				KEY user_email (user_email)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"CREATE TABLE wp_usermeta (
				umeta_id bigint(20) unsigned NOT NULL auto_increment,
				user_id bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY  (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"CREATE TABLE wp_posts (
				ID bigint(20) unsigned NOT NULL auto_increment,
				post_author bigint(20) unsigned NOT NULL default '0',
				post_date datetime NOT NULL default '0000-00-00 00:00:00',
				post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				post_content longtext NOT NULL,
				post_title text NOT NULL,
				post_excerpt text NOT NULL,
				post_status varchar(20) NOT NULL default 'publish',
				comment_status varchar(20) NOT NULL default 'open',
				ping_status varchar(20) NOT NULL default 'open',
				post_password varchar(255) NOT NULL default '',
				post_name varchar(200) NOT NULL default '',
				to_ping text NOT NULL,
				pinged text NOT NULL,
				post_modified datetime NOT NULL default '0000-00-00 00:00:00',
				post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				post_content_filtered longtext NOT NULL,
				post_parent bigint(20) unsigned NOT NULL default '0',
				guid varchar(255) NOT NULL default '',
				menu_order int(11) NOT NULL default '0',
				post_type varchar(20) NOT NULL default 'post',
				post_mime_type varchar(100) NOT NULL default '',
				comment_count bigint(20) NOT NULL default '0',
				PRIMARY KEY  (ID),
				KEY post_name (post_name(191)),
				KEY type_status_date (post_type,post_status,post_date,ID),
				KEY post_parent (post_parent),
				KEY post_author (post_author),
				KEY type_status_author (post_type,post_status,post_author)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"CREATE TABLE wp_postmeta (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				post_id bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY  (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			"CREATE TABLE wp_options (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY  (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
		);
	}
}
