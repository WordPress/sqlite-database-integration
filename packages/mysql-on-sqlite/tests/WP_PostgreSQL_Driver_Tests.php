<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PostgreSQL driver scaffold.
 */
class WP_PostgreSQL_Driver_Tests extends TestCase {
	/**
	 * Tests SELECT queries return fetched rows and normalized metadata.
	 */
	public function test_query_returns_rows_and_metadata(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT 1 AS id, 'ok' AS value" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'ok', $rows[0]->value );
		$this->assertSame( 'SELECT 1 AS id, \'ok\' AS value', $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => "SELECT 1 AS id, 'ok' AS value",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$column_meta = $driver->get_last_column_meta();
		$this->assertCount( 2, $column_meta );
		$this->assertSame( 'id', $column_meta[0]['name'] );
		$this->assertSame( 'wptests', $column_meta[0]['mysqli:db'] );
		$this->assertArrayHasKey( 'mysqli:type', $column_meta[0] );
		$this->assertArrayHasKey( 'mysqli:charsetnr', $column_meta[0] );
	}

	/**
	 * Tests write queries return PDO row counts.
	 */
	public function test_write_query_returns_row_count(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$result = $driver->query( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( 1, $result );
		$this->assertSame( 1, $driver->get_last_return_value() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests simple WordPress INSERT statements are translated to PostgreSQL.
	 */
	public function test_simple_wordpress_insert_with_backticks_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users (user_login TEXT NOT NULL)' );

		$insert = "INSERT INTO `wptests_users` (`user_login`) VALUES ('admin')";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame( $insert, $driver->get_last_mysql_query() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( 'INSERT INTO "wptests_users" ("user_login") VALUES (\'admin\')', $queries[0]['sql'] );
		$this->assertSame( array(), $queries[0]['params'] );

		$rows = $driver->query( "SELECT user_login FROM wptests_users WHERE user_login = 'admin'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'admin', $rows[0]->user_login );
	}

	/**
	 * Tests backticked SELECT identifiers are translated to PostgreSQL quoting.
	 */
	public function test_simple_select_with_backticked_identifiers_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, "post_title" TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", "post_title") VALUES (1, \'Hello\')' );

		$select = 'SELECT `ID`, `post_title` FROM `wptests_posts` WHERE `ID` = 1';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertSame( 'Hello', $rows[0]->post_title );
		$this->assertSame( $select, $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "ID", "post_title" FROM "wptests_posts" WHERE "ID" = 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests bare uppercase ID SELECT identifiers are quoted for PostgreSQL.
	 */
	public function test_simple_select_with_bare_uppercase_id_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, user_login TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (1, \'admin\')' );

		$select = 'SELECT ID, user_login FROM wptests_users WHERE ID = 1';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertSame( 'admin', $rows[0]->user_login );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "ID", user_login FROM wptests_users WHERE "ID" = 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests a simple SELECT with a trailing LIMIT translates uppercase WHERE identifiers.
	 */
	public function test_simple_select_with_bare_uppercase_id_where_and_limit_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_title TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_title) VALUES (1, \'Hello\')' );

		$select = 'SELECT * FROM wptests_posts WHERE ID = 1 LIMIT 1';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT * FROM wptests_posts WHERE "ID" = 1 LIMIT 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests COUNT projections translate uppercase aggregate identifiers.
	 */
	public function test_simple_select_count_with_bare_uppercase_id_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, user_login TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (1, \'admin\')' );

		$select = 'SELECT COUNT(ID) as c FROM wptests_users';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->c );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT COUNT("ID") as c FROM wptests_users',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests mixed-case comment SELECT identifiers are quoted for PostgreSQL.
	 */
	public function test_simple_select_with_mixed_case_comment_identifiers_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, "comment_post_ID" INTEGER NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID") VALUES (7, 1)' );

		$select = 'SELECT comment_ID FROM wptests_comments WHERE comment_post_ID = 1 ORDER BY comment_ID DESC';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->comment_ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "comment_ID" FROM wptests_comments WHERE "comment_post_ID" = 1 ORDER BY "comment_ID" DESC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL offset,count LIMIT syntax is translated to PostgreSQL.
	 */
	public function test_simple_select_with_mysql_offset_count_limit_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, "comment_post_ID" INTEGER NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID") VALUES (7, 1)' );

		$select = 'SELECT comment_ID FROM wptests_comments WHERE comment_post_ID = 1 ORDER BY comment_ID ASC LIMIT 0,500';
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->comment_ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "comment_ID" FROM wptests_comments WHERE "comment_post_ID" = 1 ORDER BY "comment_ID" ASC LIMIT 500 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests successive queries reset result metadata and backend query logs.
	 */
	public function test_query_resets_per_query_state(): void {
		$driver = $this->create_driver();

		$driver->query( 'SELECT 1 AS id' );
		$this->assertSame( 1, $driver->get_last_column_count() );
		$this->assertCount( 1, $driver->get_last_postgresql_queries() );

		$result = $driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );

		$this->assertSame( $result, $driver->get_query_results() );
		$this->assertSame( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)', $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests insert IDs are cast to integers when numeric.
	 */
	public function test_get_insert_id_casts_numeric_strings(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->query( "INSERT INTO t (value) VALUES ('first')" );

		$this->assertSame( 1, $driver->get_insert_id() );
	}

	/**
	 * Tests transaction methods delegate to PDO.
	 */
	public function test_transaction_methods_delegate_to_pdo(): void {
		$driver = $this->create_driver();

		$driver->beginTransaction();
		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->rollback();

		$stmt = $driver->get_connection()->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 't'" );
		$this->assertFalse( $stmt->fetchColumn() );
	}

	/**
	 * Tests the transaction alias and commit delegate to PDO.
	 */
	public function test_transaction_alias_and_commit_delegate_to_pdo(): void {
		$driver = $this->create_driver();

		$driver->begin_transaction();
		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->query( "INSERT INTO t (value) VALUES ('first')" );
		$driver->commit();

		$rows = $driver->query( 'SELECT value FROM t' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'first', $rows[0]->value );
	}

	/**
	 * Tests WordPress options upserts are translated to PostgreSQL ON CONFLICT.
	 */
	public function test_wordpress_options_upsert_is_translated_to_postgresql_on_conflict(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_options (
				option_id INTEGER PRIMARY KEY AUTOINCREMENT,
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL,
				autoload TEXT NOT NULL
			)'
		);

		$insert = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.org', 'yes')
			ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`),
			                        `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`);";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame( $insert, $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wp_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.org\', \'yes\') ON CONFLICT ("option_name") DO UPDATE SET "option_name" = excluded."option_name", "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$update = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.net', 'no')
			ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`),
			                        `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`);";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wp_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.net\', \'no\') ON CONFLICT ("option_name") DO UPDATE SET "option_name" = excluded."option_name", "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wp_options WHERE option_name = 'siteurl'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'http://example.net', $rows[0]->option_value );
		$this->assertSame( 'no', $rows[0]->autoload );
	}

	/**
	 * Tests simple WordPress UPDATE statements are translated to PostgreSQL.
	 */
	public function test_simple_wordpress_update_with_backticks_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_options (
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('key1', 'value1')" );

		$update = "UPDATE `wp_options` SET `option_value` = 'value2' WHERE `option_name` = 'key1'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( $update, $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wp_options" SET "option_value" = \'value2\' WHERE "option_name" = \'key1\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value FROM wp_options WHERE option_name = 'key1'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'value2', $rows[0]->option_value );
	}

	/**
	 * Tests simple WordPress DELETE statements are translated to PostgreSQL.
	 */
	public function test_simple_wordpress_delete_with_backticks_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_options (
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://example.org')" );
		$driver->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('home', 'http://example.org')" );

		$delete = "DELETE FROM `wp_options` WHERE `option_name` = 'siteurl'";

		$this->assertSame( 1, $driver->query( $delete ) );
		$this->assertSame( $delete, $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DELETE FROM "wp_options" WHERE "option_name" = \'siteurl\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT option_name FROM wp_options ORDER BY option_name' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'home', $rows[0]->option_name );
	}

	/**
	 * Tests bare uppercase ID in simple DELETE WHERE clauses is quoted.
	 */
	public function test_simple_delete_with_bare_uppercase_id_where_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, user_login TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (1, \'admin\')' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (2, \'editor\')' );

		$delete = 'DELETE FROM wptests_users WHERE ID != 1';

		$this->assertSame( 1, $driver->query( $delete ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DELETE FROM "wptests_users" WHERE "ID" != 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests multi-assignment WordPress UPDATE statements are translated to PostgreSQL.
	 */
	public function test_multi_assignment_wordpress_update_with_backticks_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_options (
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL,
				autoload TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('key1', 'value1', 'no')" );

		$update = "UPDATE `wp_options` SET `option_value` = 'value2', `autoload` = 'yes' WHERE `option_name` = 'key1'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wp_options" SET "option_value" = \'value2\', "autoload" = \'yes\' WHERE "option_name" = \'key1\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wp_options WHERE option_name = 'key1'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'value2', $rows[0]->option_value );
		$this->assertSame( 'yes', $rows[0]->autoload );
	}

	/**
	 * Tests unsupported ON DUPLICATE KEY INSERT shapes still reach PDO.
	 */
	public function test_unsupported_options_upsert_still_reaches_backend(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_options (
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL,
				autoload TEXT NOT NULL
			)'
		);

		$this->expectException( PDOException::class );

		$driver->query(
			"INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.org', 'yes')
			ON DUPLICATE KEY UPDATE `option_value` = 'http://example.net'"
		);
	}

	/**
	 * Tests DESCRIBE for a missing table returns an empty catalog result.
	 */
	public function test_describe_missing_table_returns_empty_result(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( 'DESCRIBE wptests_missing' );

		$this->assertSame( array(), $result );
		$this->assertSame( 'DESCRIBE wptests_missing', $driver->get_last_mysql_query() );
		$this->assertSame( 6, $driver->get_last_column_count() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'DESCRIBE', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_missing' ), $queries[0]['params'] );
	}

	/**
	 * Tests DESC returns MySQL-shaped column metadata for an existing table.
	 */
	public function test_desc_existing_table_returns_mysql_shaped_column_metadata(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( 'DESC `wptests_options`;' );

		$this->assertCount( 4, $result );
		$this->assertSame( 'DESC `wptests_options`;', $driver->get_last_mysql_query() );

		$this->assertSame( 'option_id', $result[0]->Field );
		$this->assertSame( 'bigint', $result[0]->Type );
		$this->assertSame( 'NO', $result[0]->Null );
		$this->assertSame( 'PRI', $result[0]->Key );
		$this->assertNull( $result[0]->Default );
		$this->assertSame( 'auto_increment', $result[0]->Extra );

		$this->assertSame( 'option_name', $result[1]->Field );
		$this->assertSame( 'varchar(191)', $result[1]->Type );
		$this->assertSame( 'NO', $result[1]->Null );
		$this->assertSame( 'UNI', $result[1]->Key );
		$this->assertNull( $result[1]->Default );
		$this->assertSame( '', $result[1]->Extra );

		$this->assertSame( 'option_value', $result[2]->Field );
		$this->assertSame( 'text', $result[2]->Type );
		$this->assertSame( '', $result[2]->Key );

		$this->assertSame( 'autoload', $result[3]->Field );
		$this->assertSame( 'varchar(20)', $result[3]->Type );
		$this->assertSame( "'yes'::character varying", $result[3]->Default );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'DESC `wptests_options`', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW TABLES returns MySQL-shaped catalog rows.
	 */
	public function test_show_tables_returns_mysql_shaped_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( "SHOW TABLES LIKE 'wptests_%'" );

		$this->assertCount( 3, $tables );
		$this->assertSame( 'wptests_options', $tables[0]->Tables_in_wptests );
		$this->assertSame( 'wptests_posts', $tables[1]->Tables_in_wptests );
		$this->assertSame( 'wptests_view', $tables[2]->Tables_in_wptests );
		$this->assertSame( "SHOW TABLES LIKE 'wptests_%'", $driver->get_last_mysql_query() );
		$this->assertSame( 'Tables_in_wptests', $driver->get_last_column_meta()[0]['name'] );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'information_schema.tables', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW TABLES', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_%' ), $queries[0]['params'] );

		$full_tables = $driver->query( "SHOW FULL TABLES LIKE 'wptests_%'" );

		$this->assertCount( 3, $full_tables );
		$this->assertSame( 'wptests_options', $full_tables[0]->Tables_in_wptests );
		$this->assertSame( 'BASE TABLE', $full_tables[0]->Table_type );
		$this->assertSame( 'wptests_view', $full_tables[2]->Tables_in_wptests );
		$this->assertSame( 'VIEW', $full_tables[2]->Table_type );
		$this->assertSame( 'Tables_in_wptests', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'Table_type', $driver->get_last_column_meta()[1]['name'] );
	}

	/**
	 * Tests MySQL-only runtime SET statements are ignored before reaching PDO.
	 */
	public function test_mysql_runtime_set_statements_are_noops(): void {
		$driver = $this->create_driver();

		$queries = array(
			'SET autocommit = 0',
			'SET autocommit = 1;',
			'SET default_storage_engine = InnoDB',
			'SET storage_engine = InnoDB',
			'SET foreign_key_checks = 0',
			'SET foreign_key_checks = 1',
			"SET SESSION sql_mode = ''",
			"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
		);

		foreach ( $queries as $query ) {
			$driver->query( 'SELECT 1 AS previous_value' );

			$this->assertSame( 0, $driver->query( $query ) );
			$this->assertSame( $query, $driver->get_last_mysql_query() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
			$this->assertSame( array(), $driver->get_last_column_meta() );
			$this->assertSame( 0, $driver->get_last_column_count() );
			$this->assertSame( 0, $driver->get_last_return_value() );
		}
	}

	/**
	 * Tests unsupported SET statements are still sent to PDO.
	 */
	public function test_unsupported_set_statement_still_reaches_backend(): void {
		$driver = $this->create_driver();

		$this->expectException( PDOException::class );

		$driver->query( 'SET unsupported_setting = 1' );
	}

	/**
	 * Tests multi-assignment SET statements are not silently ignored.
	 */
	public function test_multi_assignment_set_statement_still_reaches_backend(): void {
		$driver = $this->create_driver();

		$this->expectException( PDOException::class );

		$driver->query( 'SET foreign_key_checks = 0, unsupported_setting = 1' );
	}

	/**
	 * Creates a PostgreSQL driver backed by an injected in-memory PDO.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Install a small information_schema fixture into the injected PDO.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_information_schema_fixture( WP_PostgreSQL_Driver $driver ): void {
		$pdo = $driver->get_connection()->get_pdo();

		$pdo->exec( "ATTACH DATABASE ':memory:' AS information_schema" );
		$pdo->exec(
			'CREATE TABLE information_schema.tables (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				table_type TEXT NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.columns (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				column_name TEXT NOT NULL,
				ordinal_position INTEGER NOT NULL,
				data_type TEXT NOT NULL,
				character_maximum_length INTEGER,
				is_nullable TEXT NOT NULL,
				column_default TEXT,
				is_identity TEXT NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.table_constraints (
				constraint_schema TEXT NOT NULL,
				constraint_name TEXT NOT NULL,
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				constraint_type TEXT NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.key_column_usage (
				constraint_schema TEXT NOT NULL,
				constraint_name TEXT NOT NULL,
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				column_name TEXT NOT NULL
			)'
		);

		$pdo->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_options', 'BASE TABLE'),
				('public', 'wptests_posts', 'BASE TABLE'),
				('public', 'wptests_view', 'VIEW'),
				('other', 'other_table', 'BASE TABLE')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.columns
				(table_schema, table_name, column_name, ordinal_position, data_type, character_maximum_length, is_nullable, column_default, is_identity)
			VALUES
				('public', 'wptests_options', 'option_id', 1, 'bigint', NULL, 'NO', NULL, 'YES'),
				('public', 'wptests_options', 'option_name', 2, 'character varying', 191, 'NO', NULL, 'NO'),
				('public', 'wptests_options', 'option_value', 3, 'text', NULL, 'NO', NULL, 'NO'),
				('public', 'wptests_options', 'autoload', 4, 'character varying', 20, 'NO', '''yes''::character varying', 'NO')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.table_constraints
				(constraint_schema, constraint_name, table_schema, table_name, constraint_type)
			VALUES
				('public', 'wptests_options_pkey', 'public', 'wptests_options', 'PRIMARY KEY'),
				('public', 'wptests_options_option_name_key', 'public', 'wptests_options', 'UNIQUE')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.key_column_usage
				(constraint_schema, constraint_name, table_schema, table_name, column_name)
			VALUES
				('public', 'wptests_options_pkey', 'public', 'wptests_options', 'option_id'),
				('public', 'wptests_options_option_name_key', 'public', 'wptests_options', 'option_name')"
		);
	}
}
