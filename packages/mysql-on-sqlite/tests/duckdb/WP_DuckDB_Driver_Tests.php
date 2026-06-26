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
					'Collation'  => 'utf8mb4_unicode_ci',
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
					'id'   => 3,
					'name' => 'third',
				),
			),
			$driver->query( 'SELECT id, name FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
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

	public function test_unsupported_alter_table_shape_throws_driver_exception(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'Unsupported ALTER TABLE statement in DuckDB driver. Only ADD INDEX is supported.' );
		$driver->query( 'ALTER TABLE users ADD COLUMN email VARCHAR(255)' );
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
