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
		$this->assertStringStartsWith( 'CREATE INDEX IF NOT EXISTS "wp_duckdb_idx_', $driver->get_last_duckdb_queries()[0] );

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
