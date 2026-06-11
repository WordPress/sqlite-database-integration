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
	 * Tests non-strict INSERT statements append metadata-derived NOT NULL defaults.
	 */
	public function test_non_strict_insert_appends_omitted_not_null_defaults_from_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_comments (
				comment_ID INTEGER PRIMARY KEY,
				comment_author TEXT NOT NULL,
				comment_author_email TEXT NOT NULL,
				comment_content TEXT NOT NULL,
				comment_parent INTEGER NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_comments (
				comment_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_author tinytext NOT NULL,
				comment_author_email varchar(100) NOT NULL DEFAULT '',
				comment_content text NOT NULL,
				comment_parent bigint(20) unsigned NOT NULL DEFAULT '0',
				PRIMARY KEY (comment_ID)
			)"
		);

		$comment_insert = 'INSERT INTO `wptests_comments` (`comment_ID`) VALUES (1)';

		$this->assertSame( 1, $driver->query( $comment_insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_comments" ("comment_ID", "comment_author", "comment_author_email", "comment_content", "comment_parent") VALUES (1, \'\', \'\', \'\', \'0\')',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$comments = $driver->query( 'SELECT comment_author, comment_author_email, comment_content, comment_parent FROM wptests_comments WHERE comment_ID = 1' );
		$this->assertSame( '', $comments[0]->comment_author );
		$this->assertSame( '', $comments[0]->comment_author_email );
		$this->assertSame( '', $comments[0]->comment_content );
		$this->assertSame( '0', $comments[0]->comment_parent );

		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY,
				post_date TEXT NOT NULL,
				post_content TEXT NOT NULL,
				post_title TEXT NOT NULL,
				post_excerpt TEXT NOT NULL,
				post_status TEXT NOT NULL,
				post_parent INTEGER NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_content longtext NOT NULL,
				post_title text NOT NULL,
				post_excerpt text NOT NULL,
				post_status varchar(20) NOT NULL DEFAULT 'publish',
				post_parent bigint(20) unsigned NOT NULL DEFAULT '0',
				PRIMARY KEY (ID)
			)"
		);

		$post_insert = "INSERT INTO `wptests_posts` (`ID`, `post_title`) VALUES (1, 'Post 1')";

		$this->assertSame( 1, $driver->query( $post_insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_posts" ("ID", "post_title", "post_date", "post_content", "post_excerpt", "post_status", "post_parent") VALUES (1, \'Post 1\', \'0000-00-00 00:00:00\', \'\', \'\', \'publish\', \'0\')',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$posts = $driver->query( 'SELECT post_date, post_content, post_excerpt, post_status, post_parent FROM wptests_posts WHERE ID = 1' );
		$this->assertSame( '0000-00-00 00:00:00', $posts[0]->post_date );
		$this->assertSame( '', $posts[0]->post_content );
		$this->assertSame( '', $posts[0]->post_excerpt );
		$this->assertSame( 'publish', $posts[0]->post_status );
		$this->assertSame( '0', $posts[0]->post_parent );
	}

	/**
	 * Tests strict SQL mode leaves omitted NOT NULL INSERT columns to fail visibly.
	 */
	public function test_strict_insert_does_not_append_omitted_not_null_defaults(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_comments (
				comment_ID INTEGER PRIMARY KEY,
				comment_author TEXT NOT NULL,
				comment_content TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_comments (
				comment_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_author tinytext NOT NULL,
				comment_content text NOT NULL,
				PRIMARY KEY (comment_ID)
			)'
		);
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' );

		$this->expectException( PDOException::class );

		$driver->query( 'INSERT INTO `wptests_comments` (`comment_ID`) VALUES (1)' );
	}

	/**
	 * Tests explicit identity INSERT statements repair PostgreSQL sequences after success.
	 */
	public function test_explicit_identity_insert_repairs_sequence_after_success(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_terms', 'term_id', 'wptests_terms_term_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );

		$insert = "INSERT INTO `wptests_terms` (`term_id`, `name`) VALUES (7, 'identity')";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame( 'INSERT INTO "wptests_terms" ("term_id", "name") VALUES (7, \'identity\')', $queries[0]['sql'] );
		$this->assert_sequence_repair_query( $queries[1], 'wptests_terms', 'term_id', 'wptests_terms_term_id_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests implicit identity INSERT statements do not repair PostgreSQL sequences.
	 */
	public function test_implicit_identity_insert_does_not_repair_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_terms', 'term_id', 'wptests_terms_term_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );

		$insert = "INSERT INTO `wptests_terms` (`name`) VALUES ('implicit')";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( 'INSERT INTO "wptests_terms" ("name") VALUES (\'implicit\')', $queries[0]['sql'] );
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests INSERT IGNORE no-op conflicts do not repair PostgreSQL sequences.
	 */
	public function test_insert_ignore_noop_does_not_repair_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_terms', 'term_id', 'wptests_terms_term_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_terms (term_id, name) VALUES (7, \'existing\')' );

		$insert = "INSERT IGNORE INTO `wptests_terms` (`term_id`, `name`) VALUES (7, 'duplicate')";

		$this->assertSame( 0, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( 'INSERT INTO "wptests_terms" ("term_id", "name") VALUES (7, \'duplicate\') ON CONFLICT DO NOTHING', $queries[0]['sql'] );
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests failed explicit identity INSERT statements do not repair PostgreSQL sequences.
	 */
	public function test_failed_identity_insert_does_not_repair_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_terms', 'term_id', 'wptests_terms_term_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_terms (term_id, name) VALUES (7, \'existing\')' );

		try {
			$driver->query( "INSERT INTO `wptests_terms` (`term_id`, `name`) VALUES (7, 'duplicate')" );
			$this->fail( 'Duplicate explicit identity INSERT should fail before sequence repair.' );
		} catch ( PDOException $e ) {
			$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
		}
	}

	/**
	 * Tests simple WordPress REPLACE statements update through PostgreSQL upserts.
	 */
	public function test_simple_wordpress_replace_with_existing_id_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", display_name) VALUES (2, \'Walter Sobchak\')' );

		$replace = "REPLACE INTO `wptests_users` (`ID`, `display_name`) VALUES (2, 'Walter Replace Sobchak')";

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertSame( $replace, $driver->get_last_mysql_query() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_users" ("ID", "display_name") VALUES (2, \'Walter Replace Sobchak\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$queries[0]['sql']
		);

		$rows = $driver->query( 'SELECT display_name FROM wptests_users WHERE "ID" = 2' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Walter Replace Sobchak', $rows[0]->display_name );
	}

	/**
	 * Tests non-strict REPLACE applies omitted NOT NULL defaults on insert and conflict paths.
	 */
	public function test_non_strict_replace_appends_omitted_not_null_defaults_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_options_table_with_mysql_metadata( $driver );

		$replace      = "REPLACE INTO `wptests_options` (`option_name`) VALUES ('siteurl')";
		$expected_sql = 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'\', \'yes\') ON CONFLICT ("option_name") DO UPDATE SET "option_name" = excluded."option_name", "option_value" = excluded."option_value", "autoload" = excluded."autoload"';

		$this->assertSame( 1, $driver->query( $replace ) );
		$this->assertSame(
			array(
				array(
					'sql'    => $expected_sql,
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );
		$this->assertSame( '', $rows[0]->option_value );
		$this->assertSame( 'yes', $rows[0]->autoload );

		$driver->query( "UPDATE wptests_options SET option_value = 'custom', autoload = 'no' WHERE option_name = 'siteurl'" );

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertSame(
			array(
				array(
					'sql'    => $expected_sql,
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );
		$this->assertSame( '', $rows[0]->option_value );
		$this->assertSame( 'yes', $rows[0]->autoload );
	}

	/**
	 * Tests REPLACE insert paths with explicit identity values repair PostgreSQL sequences.
	 */
	public function test_replace_insert_path_with_explicit_identity_repairs_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_users', 'ID', 'wptests_users_ID_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );

		$replace = "REPLACE INTO `wptests_users` (`ID`, `display_name`) VALUES (2, 'Donny Kerabatsos')";

		$this->assertSame( 1, $driver->query( $replace ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_users" ("ID", "display_name") VALUES (2, \'Donny Kerabatsos\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$queries[0]['sql']
		);
		$this->assert_sequence_repair_query( $queries[1], 'wptests_users', 'ID', 'wptests_users_ID_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests REPLACE conflict update paths do not repair PostgreSQL sequences.
	 */
	public function test_replace_conflict_update_path_does_not_repair_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_users', 'ID', 'wptests_users_ID_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", display_name) VALUES (2, \'Walter Sobchak\')' );

		$replace = "REPLACE INTO `wptests_users` (`ID`, `display_name`) VALUES (2, 'Walter Replace Sobchak')";

		$this->assertSame( 2, $driver->query( $replace ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_users" ("ID", "display_name") VALUES (2, \'Walter Replace Sobchak\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$queries[0]['sql']
		);
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests simple REPLACE without a known conflict column falls back to INSERT.
	 */
	public function test_simple_wordpress_replace_without_known_conflict_column_is_inserted(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts (post_name TEXT NOT NULL, post_status TEXT NOT NULL)' );

		$replace = "REPLACE INTO `wptests_posts` (`post_name`, `post_status`) VALUES ('hello-world', 'publish')";

		$this->assertSame( 1, $driver->query( $replace ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_posts" ("post_name", "post_status") VALUES (\'hello-world\', \'publish\')',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL temporary table cleanup drops are translated for PostgreSQL.
	 */
	public function test_drop_temporary_table_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TEMPORARY TABLE wptests_temp_cleanup (value TEXT)' );

		$this->assertTrue( $this->sqlite_table_exists( $driver, 'temp', 'wptests_temp_cleanup' ) );
		$this->assertSame( 0, $driver->query( 'DROP TEMPORARY TABLE wptests_temp_cleanup' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP TABLE temp."wptests_temp_cleanup"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
		$this->assertFalse( $this->sqlite_table_exists( $driver, 'temp', 'wptests_temp_cleanup' ) );
	}

	/**
	 * Tests temporary drops never delete a permanent table when no temp table exists.
	 */
	public function test_drop_temporary_table_without_matching_temp_table_does_not_drop_permanent_table(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_permanent_temp_probe (id INTEGER NOT NULL)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_permanent_temp_probe (id int NOT NULL)' );

		$columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_permanent_temp_probe' );

		try {
			$driver->query( 'DROP TEMPORARY TABLE wptests_permanent_temp_probe' );
			$this->fail( 'DROP TEMPORARY TABLE without an active temp table should fail without dropping the permanent table.' );
		} catch ( PDOException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}

		$this->assertTrue( $this->sqlite_table_exists( $driver, 'main', 'wptests_permanent_temp_probe' ) );
		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_permanent_temp_probe' ) );
	}

	/**
	 * Tests temporary IF EXISTS drops no-op without deleting a permanent table.
	 */
	public function test_drop_temporary_table_if_exists_without_matching_temp_table_does_not_drop_permanent_table(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_permanent_temp_exists_probe (id INTEGER NOT NULL)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_permanent_temp_exists_probe (id int NOT NULL)' );

		$columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_permanent_temp_exists_probe' );

		$driver->query( 'DROP TEMPORARY TABLE IF EXISTS wptests_permanent_temp_exists_probe' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP TABLE IF EXISTS temp."wptests_permanent_temp_exists_probe"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertTrue( $this->sqlite_table_exists( $driver, 'main', 'wptests_permanent_temp_exists_probe' ) );
		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_permanent_temp_exists_probe' ) );
	}

	/**
	 * Tests temporary table creation with MySQL CHARACTER SET syntax is translated.
	 */
	public function test_create_temporary_table_with_character_set_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();
		$query  = 'CREATE TEMPORARY TABLE wptests_charset_temp ( a VARCHAR(50) CHARACTER SET big5, b TEXT CHARACTER SET big5 )';

		$this->assertSame( 0, $driver->query( $query ) );
		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TEMPORARY TABLE \"wptests_charset_temp\" (\n  \"a\" varchar(50),\n  \"b\" text\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests temporary DDL does not clobber permanent MySQL schema metadata.
	 */
	public function test_temporary_create_and_drop_do_not_clobber_permanent_mysql_schema_metadata(): void {
		$driver = $this->create_driver();

		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_shadow_metadata (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
				body longtext CHARACTER SET koi8r COLLATE koi8r_general_ci NOT NULL,
				PRIMARY KEY (id),
				KEY title (title(20))
			)"
		);

		$columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_shadow_metadata' );
		$indexes_before = $this->get_mysql_index_metadata_rows( $driver, 'wptests_shadow_metadata' );

		$this->assertSame( array( 'id', 'title', 'body' ), array_column( $columns_before, 'column_name' ) );
		$this->assertSame( 'longtext', $columns_before[2]['column_type'] );
		$this->assertSame( 'koi8r_general_ci', $columns_before[2]['collation_name'] );
		$this->assertSame( array( 'PRIMARY', 'title' ), array_values( array_unique( array_column( $indexes_before, 'key_name' ) ) ) );
		$this->assertSame( '20', $indexes_before[1]['sub_part'] );

		$driver->query( 'CREATE TEMPORARY TABLE wptests_shadow_metadata (temp_value varchar(10) CHARACTER SET latin1)' );

		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_shadow_metadata' ) );
		$this->assertSame( $indexes_before, $this->get_mysql_index_metadata_rows( $driver, 'wptests_shadow_metadata' ) );

		$driver->query( 'DROP TEMPORARY TABLE wptests_shadow_metadata' );

		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_shadow_metadata' ) );
		$this->assertSame( $indexes_before, $this->get_mysql_index_metadata_rows( $driver, 'wptests_shadow_metadata' ) );
	}

	/**
	 * Tests temporary CREATE TABLE stores isolated MySQL metadata for dbDelta introspection.
	 */
	public function test_temporary_create_stores_temporary_mysql_schema_metadata_for_dbdelta_introspection(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query( 'CREATE TABLE wptests_dbdelta_temp_probe (permanent_value INTEGER NOT NULL)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_dbdelta_temp_probe (permanent_value int NOT NULL)' );

		$public_columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_temp_probe' );
		$this->assertSame( array( 'permanent_value' ), array_column( $public_columns_before, 'column_name' ) );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_dbdelta_temp_probe (
				`id` bigint(20) NOT NULL,
				`references` varchar(255) NOT NULL,
				PRIMARY KEY (`id`),
				KEY `compound_key` (`id`,`references`(191))
			)'
		);

		$temp_columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_temp_probe', 'temp' );
		$this->assertSame( array( 'id', 'references' ), array_column( $temp_columns, 'column_name' ) );
		$this->assertSame( array( 'bigint(20)', 'varchar(255)' ), array_column( $temp_columns, 'column_type' ) );
		$this->assertSame( $public_columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_temp_probe' ) );

		$describe = $driver->query( 'DESCRIBE wptests_dbdelta_temp_probe' );
		$this->assertSame( 'id', $describe[0]->Field );
		$this->assertSame( 'PRI', $describe[0]->Key );
		$this->assertSame( 'references', $describe[1]->Field );
		$this->assertSame( 'MUL', $describe[1]->Key );
		$this->assertSame( array( 'temp', 'wptests_dbdelta_temp_probe' ), $driver->get_last_postgresql_queries()[0]['params'] );

		$temp_indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_dbdelta_temp_probe', 'temp' );
		$this->assertSame( array( 'PRIMARY', 'compound_key', 'compound_key' ), array_column( $temp_indexes, 'key_name' ) );
		$this->assertSame( array( 'id', 'id', 'references' ), array_column( $temp_indexes, 'column_name' ) );
		$this->assertSame( '191', $temp_indexes[2]['sub_part'] );

		$driver->query( 'DROP TEMPORARY TABLE wptests_dbdelta_temp_probe' );

		$this->assertSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_temp_probe', 'temp' ) );
		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_dbdelta_temp_probe', 'temp' ) );
		$this->assertSame( $public_columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_temp_probe' ) );
	}

	/**
	 * Tests unqualified DROP TABLE removes active temporary metadata before permanent metadata.
	 */
	public function test_unqualified_drop_table_removes_temporary_metadata_without_clobbering_permanent_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_dbdelta_shadow_drop (permanent_value INTEGER NOT NULL)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_dbdelta_shadow_drop (permanent_value int NOT NULL)' );

		$public_columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_shadow_drop' );

		$driver->query( 'CREATE TEMPORARY TABLE wptests_dbdelta_shadow_drop (`temp_value` varchar(50) NOT NULL)' );
		$temp_columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_shadow_drop', 'temp' );
		$this->assertSame( array( 'temp_value' ), array_column( $temp_columns, 'column_name' ) );

		$driver->query( 'DROP TABLE IF EXISTS wptests_dbdelta_shadow_drop' );

		$this->assertFalse( $this->sqlite_table_exists( $driver, 'temp', 'wptests_dbdelta_shadow_drop' ) );
		$this->assertTrue( $this->sqlite_table_exists( $driver, 'main', 'wptests_dbdelta_shadow_drop' ) );
		$this->assertSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_shadow_drop', 'temp' ) );
		$this->assertSame( $public_columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_dbdelta_shadow_drop' ) );
	}

	/**
	 * Tests plain CHAR columns do not route through the MySQL DDL translator.
	 */
	public function test_create_table_with_plain_char_and_check_preserves_constraint(): void {
		$driver = $this->create_driver();
		$query  = 'CREATE TABLE plain_char_check (a CHAR(10) CHECK (length(a) > 0))';

		$this->assertSame( 0, $driver->query( $query ) );
		$this->assertSame(
			array(
				array(
					'sql'    => $query,
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->expectException( PDOException::class );
		$driver->query( "INSERT INTO plain_char_check (a) VALUES ('')" );
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
	 * Tests MySQL offset,count LIMIT syntax is translated in broader SELECT queries.
	 */
	public function test_complex_select_with_mysql_offset_count_limit_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, "comment_post_ID" INTEGER NOT NULL, comment_approved TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_approved) VALUES (1, 7, \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_approved) VALUES (2, 7, \'1\')' );

		$select = "SELECT comment_post_ID, COUNT(comment_ID) as num_comments
			FROM wptests_comments
			WHERE comment_post_ID IN (7) AND comment_approved = '1'
			GROUP BY comment_post_ID
			ORDER BY comment_post_ID ASC
			LIMIT 0, 10";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->comment_post_ID );
		$this->assertSame( '2', $rows[0]->num_comments );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "comment_post_ID", COUNT ("comment_ID") as num_comments FROM wptests_comments WHERE "comment_post_ID" IN (7) AND comment_approved = \'1\' GROUP BY "comment_post_ID" ORDER BY "comment_post_ID" ASC LIMIT 10 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL offset,count LIMIT variants are translated to LIMIT/OFFSET.
	 */
	public function test_mysql_offset_count_limit_variants_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$cases = array(
			'SELECT * FROM wptests_posts LIMIT 0, 10'    => 'SELECT * FROM wptests_posts LIMIT 10 OFFSET 0',
			'SELECT * FROM wptests_posts LIMIT 5, 10'    => 'SELECT * FROM wptests_posts LIMIT 10 OFFSET 5',
			"SELECT * FROM wptests_posts LIMIT\n0 ,\n10" => 'SELECT * FROM wptests_posts LIMIT 10 OFFSET 0',
			'SELECT * FROM wptests_posts LIMIT ?, ?'     => 'SELECT * FROM wptests_posts LIMIT ? OFFSET ?',
		);

		foreach ( $cases as $mysql_sql => $postgresql_sql ) {
			$this->assertSame(
				$postgresql_sql,
				$this->translate_driver_query_with_private_method( $driver, 'translate_simple_mysql_select_query', $mysql_sql )
			);
		}
	}

	/**
	 * Tests PostgreSQL LIMIT count OFFSET offset syntax is preserved.
	 */
	public function test_existing_limit_offset_clause_is_preserved(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts (ID INTEGER)' );

		$select = 'SELECT * FROM wptests_posts LIMIT 10 OFFSET 5';

		$driver->query( $select );

		$this->assertSame(
			array(
				array(
					'sql'    => $select,
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
	 * Tests non-strict UPDATE coerces exact NULL assignments for NOT NULL columns.
	 */
	public function test_non_strict_update_null_coerces_not_null_columns_to_metadata_defaults(): void {
		$driver = $this->create_driver();
		$this->install_options_table_with_mysql_metadata( $driver );

		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('cron', 'serialized', 'no')" );

		$update = "UPDATE `wptests_options` SET `option_value` = NULL, `autoload` = NULL WHERE `option_name` = 'cron'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_options" SET "option_value" = \'\', "autoload" = \'yes\' WHERE "option_name" = \'cron\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'cron'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]->option_value );
		$this->assertSame( 'yes', $rows[0]->autoload );
	}

	/**
	 * Tests strict SQL mode leaves UPDATE NULL assignments to fail visibly.
	 */
	public function test_strict_update_null_does_not_coerce_not_null_columns(): void {
		$driver = $this->create_driver();
		$this->install_options_table_with_mysql_metadata( $driver );

		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('cron', 'serialized', 'no')" );
		$driver->set_sql_mode( 'STRICT_ALL_TABLES' );

		$this->expectException( PDOException::class );

		$driver->query( "UPDATE `wptests_options` SET `option_value` = NULL WHERE `option_name` = 'cron'" );
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
	 * Tests WordPress expired transient cleanup DELETE statements are translated.
	 */
	public function test_wordpress_expired_transients_delete_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_options (
				option_name TEXT NOT NULL,
				option_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_options (option_name, option_value) VALUES ('_transient_expired', 'value')" );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value) VALUES ('_transient_timeout_expired', '100')" );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value) VALUES ('_transient_fresh', 'value')" );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value) VALUES ('_transient_timeout_fresh', '9999999999')" );

		$delete = "DELETE a, b FROM wptests_options a, wptests_options b
			WHERE a.option_name LIKE '\\_transient\\_%'
			AND a.option_name NOT LIKE '\\_transient\\_timeout\\_%'
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < 200";

		$driver->query( $delete );

		$this->assertSame( $delete, $driver->get_last_mysql_query() );
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WITH expired_transients AS', $queries[0]['sql'] );
		$this->assertStringContainsString( 'DELETE FROM "wptests_options"', $queries[0]['sql'] );
		$this->assertStringContainsString( 'SUBSTR(a.option_name, 12)', $queries[0]['sql'] );

		$rows = $driver->query( 'SELECT option_name FROM wptests_options ORDER BY option_name' );

		$this->assertSame(
			array( '_transient_fresh', '_transient_timeout_fresh' ),
			array_map(
				function ( $row ) {
					return $row->option_name;
				},
				$rows
			)
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
	 * Tests complex SELECT statements quote mixed-case WordPress identifiers.
	 */
	public function test_complex_select_quotes_mixed_case_wordpress_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_comments (
				"comment_post_ID" INTEGER NOT NULL,
				comment_approved TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_comments (\"comment_post_ID\", comment_approved) VALUES (1, '1')" );

		$select = "SELECT COUNT(*) FROM wptests_comments WHERE comment_post_ID = 1 AND comment_approved = '1'";
		$rows   = $driver->query( $select );

		$this->assertSame( '1', array_values( get_object_vars( $rows[0] ) )[0] );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT COUNT (*) FROM wptests_comments WHERE "comment_post_ID" = 1 AND comment_approved = \'1\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests complex JOIN queries quote qualified mixed-case WordPress identifiers.
	 */
	public function test_complex_join_select_quotes_qualified_mixed_case_wordpress_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_postmeta (post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (1, 'nav_menu_item', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, '_menu_item_object_id', '2')" );

		$select = "SELECT wptests_posts.*
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE wptests_postmeta.meta_key = '_menu_item_object_id'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts.* FROM wptests_posts INNER JOIN wptests_postmeta ON (wptests_posts."ID" = wptests_postmeta.post_id) WHERE wptests_postmeta.meta_key = \'_menu_item_object_id\' GROUP BY wptests_posts."ID" ORDER BY wptests_posts.post_date DESC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests CONVERT(expr USING charset) expressions are translated to PostgreSQL.
	 */
	public function test_convert_using_expression_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_convert (value TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_convert (value) VALUES ('Customer')" );
		$driver->query( "INSERT INTO wptests_convert (value) VALUES ('Other')" );

		$select = "SELECT CONVERT(value USING utf8mb4) AS converted
			FROM wptests_convert
			WHERE CONVERT(value USING utf8mb4) = 'Customer'
			ORDER BY CONVERT(value USING utf8mb4)";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Customer', $rows[0]->converted );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT (value) AS converted FROM wptests_convert WHERE (value) = \'Customer\' ORDER BY (value)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests direct MySQL collations on CONVERT(expr USING charset) are omitted.
	 */
	public function test_convert_using_expression_omits_direct_mysql_collation(): void {
		$driver = $this->create_driver();

		$select = "SELECT CONVERT('Customer' USING utf8mb4) COLLATE utf8mb4_bin AS value";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Customer', $rows[0]->value );
		$this->assertSame(
			array(
				array(
					'sql'    => "SELECT ('Customer') AS value",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests compound CONVERT(expr USING charset) expressions preserve grouping.
	 */
	public function test_convert_using_compound_expression_preserves_grouping(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SELECT CONVERT(1 + 2 USING utf8mb4) * 3 AS value' );

		$this->assertSame( '9', $rows[0]->value );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT (1 + 2) * 3 AS value',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests right-hand compound CONVERT(expr USING charset) expressions preserve grouping.
	 */
	public function test_convert_using_right_hand_compound_expression_preserves_grouping(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SELECT 10 - CONVERT(1 + 2 USING utf8mb4) AS value' );

		$this->assertSame( '7', $rows[0]->value );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT 10 - (1 + 2) AS value',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL FIELD() expressions are translated for PostgreSQL ordering.
	 */
	public function test_field_function_is_translated_to_postgresql_case_expression(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_name) VALUES (1, \'alpha\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_name) VALUES (2, \'beta\')' );

		$select = 'SELECT ID FROM wptests_posts WHERE ID IN (1, 2) ORDER BY FIELD(ID, 2, 1)';
		$rows   = $driver->query( $select );

		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( '1', $rows[1]->ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "ID" FROM wptests_posts WHERE "ID" IN (1, 2) ORDER BY CASE WHEN "ID" IS NULL THEN 0 WHEN CAST("ID" AS text) = CAST(2 AS text) THEN 1 WHEN CAST("ID" AS text) = CAST(1 AS text) THEN 2 ELSE 0 END',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests lowercase field() calls trigger PostgreSQL compatibility translation.
	 */
	public function test_lowercase_field_function_triggers_postgresql_rewrite(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts (post_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts (post_name) VALUES (\'alpha\')' );
		$driver->query( 'INSERT INTO wptests_posts (post_name) VALUES (\'beta\')' );

		$rows = $driver->query( "SELECT post_name FROM wptests_posts ORDER BY field(post_name, 'beta', 'alpha')" );

		$this->assertSame( 'beta', $rows[0]->post_name );
		$this->assertSame( 'alpha', $rows[1]->post_name );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT post_name FROM wptests_posts ORDER BY CASE WHEN post_name IS NULL THEN 0 WHEN CAST(post_name AS text) = CAST(\'beta\' AS text) THEN 1 WHEN CAST(post_name AS text) = CAST(\'alpha\' AS text) THEN 2 ELSE 0 END',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests FIELD() returns zero for NULL and missing values.
	 */
	public function test_field_function_returns_zero_for_null_and_missing_values(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT FIELD(NULL, 1) AS null_position, FIELD('missing', 'alpha') AS missing_position, FIELD('alpha', 'beta', 'alpha') AS alpha_position" );

		$this->assertSame( '0', $rows[0]->null_position );
		$this->assertSame( '0', $rows[0]->missing_position );
		$this->assertSame( '2', $rows[0]->alpha_position );
	}

	/**
	 * Tests integer-column IN predicates coerce string literals using stored MySQL metadata.
	 */
	public function test_integer_column_in_string_literals_use_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_comments (
				`comment_ID` bigint(20) unsigned NOT NULL,
				`comment_post_ID` bigint(20) unsigned NOT NULL DEFAULT 0,
				`comment_approved` varchar(20) NOT NULL DEFAULT "1",
				PRIMARY KEY (`comment_ID`)
			)'
		);
		$driver->query(
			'INSERT INTO wptests_comments (`comment_ID`, `comment_post_ID`, `comment_approved`) ' .
			'VALUES (1, 0, \'0\')'
		);
		$driver->query(
			'INSERT INTO wptests_comments (`comment_ID`, `comment_post_ID`, `comment_approved`) ' .
			'VALUES (2, 1, \'0\')'
		);
		$driver->query(
			'INSERT INTO wptests_comments (`comment_ID`, `comment_post_ID`, `comment_approved`) ' .
			'VALUES (3, 0, \'1\')'
		);

		$select = "SELECT comment_post_ID, COUNT(comment_ID) as num_comments
			FROM wptests_comments
			WHERE comment_post_ID IN ('') AND comment_approved = '0'
			GROUP BY comment_post_ID";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '0', $rows[0]->comment_post_ID );
		$this->assertSame( '1', $rows[0]->num_comments );

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString(
			'"comment_post_ID" IN (' . $this->get_expected_mysql_integer_cast_sql( "''" ) . ')',
			$sql
		);
		$this->assertStringContainsString( "comment_approved = '0'", $sql );
		$this->assertStringNotContainsString( 'CAST(comment_approved AS text)', $sql );
	}

	/**
	 * Tests SQL_CALC_FOUND_ROWS user searches coerce bad ID terms without breaking LIKE terms.
	 */
	public function test_sql_calc_found_rows_user_search_coerces_integer_id_string_predicate(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_users (
				`ID` bigint(20) unsigned NOT NULL,
				`user_login` varchar(60) NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( 'INSERT INTO wptests_users (`ID`, `user_login`) VALUES (1, \'admin\')' );
		$driver->query( 'INSERT INTO wptests_users (`ID`, `user_login`) VALUES (2, \'match-yololololo\')' );

		$select = "SELECT SQL_CALC_FOUND_ROWS ID, user_login
			FROM wptests_users
			WHERE ID = 'yololololo' OR user_login LIKE '%yololololo%'
			ORDER BY ID ASC";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( 'match-yololololo', $rows[0]->user_login );

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $sql );
		$this->assertStringContainsString(
			'"ID" = ' . $this->get_expected_mysql_integer_cast_sql( "'yololololo'" ),
			$sql
		);
		$this->assertStringContainsString( "user_login LIKE '%yololololo%'", $sql );
	}

	/**
	 * Tests text columns keep lexical string comparisons even when numeric-looking values are present.
	 */
	public function test_text_columns_preserve_lexical_string_comparisons(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_users (
				`ID` bigint(20) unsigned NOT NULL,
				`user_login` varchar(60) NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`meta_id` bigint(20) unsigned NOT NULL,
				`meta_value` longtext NOT NULL,
				PRIMARY KEY (`meta_id`)
			)'
		);
		$driver->query( 'INSERT INTO wptests_users (`ID`, `user_login`) VALUES (7, \'007\')' );
		$driver->query( 'INSERT INTO wptests_users (`ID`, `user_login`) VALUES (8, \'7\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (`meta_id`, `meta_value`) VALUES (1, \'10abc\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (`meta_id`, `meta_value`) VALUES (2, \'10\')' );

		$user_rows = $driver->query( "SELECT ID FROM wptests_users WHERE user_login = '007'" );

		$this->assertCount( 1, $user_rows );
		$this->assertSame( '7', $user_rows[0]->ID );
		$this->assertStringNotContainsString( 'SUBSTRING(CAST', $driver->get_last_postgresql_queries()[0]['sql'] );

		$meta_rows = $driver->query( "SELECT meta_id FROM wptests_postmeta WHERE meta_value = '10abc'" );

		$this->assertCount( 1, $meta_rows );
		$this->assertSame( '1', $meta_rows[0]->meta_id );
		$this->assertStringNotContainsString( 'SUBSTRING(CAST', $driver->get_last_postgresql_queries()[0]['sql'] );

		$like_rows = $driver->query( "SELECT meta_id FROM wptests_postmeta WHERE meta_value LIKE '10%' ORDER BY meta_id" );

		$this->assertCount( 2, $like_rows );
		$this->assertSame( '1', $like_rows[0]->meta_id );
		$this->assertSame( '2', $like_rows[1]->meta_id );
		$this->assertStringContainsString( "meta_value LIKE '10%'", $driver->get_last_postgresql_queries()[0]['sql'] );
		$this->assertStringNotContainsString( 'SUBSTRING(CAST', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests text metadata columns use MySQL numeric coercion when compared with numeric literals.
	 */
	public function test_text_metadata_numeric_literal_comparisons_use_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'score', '100')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'score', '')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, 'score', 'abc')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (4, 'score', '20abc')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (5, 'other', '1')" );

		$rows = $driver->query(
			"SELECT post_id FROM wptests_postmeta WHERE meta_key = 'score' AND meta_value < 50 ORDER BY post_id"
		);

		$this->assertSame(
			array( '2', '3', '4' ),
			array_map(
				static function ( $row ): string {
					return $row->post_id;
				},
				$rows
			)
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'meta_value' );
		$sql                 = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString( $meta_value_cast_sql . ' < 50', $sql );
		$this->assertStringContainsString( "meta_key = 'score'", $sql );

		$mirrored_rows = $driver->query(
			"SELECT post_id FROM wptests_postmeta WHERE meta_key = 'score' AND 50 > meta_value ORDER BY post_id"
		);

		$this->assertSame(
			array( '2', '3', '4' ),
			array_map(
				static function ( $row ): string {
					return $row->post_id;
				},
				$mirrored_rows
			)
		);
		$this->assertStringContainsString( '50 > ' . $meta_value_cast_sql, $driver->get_last_postgresql_queries()[0]['sql'] );

		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (6, 'score', '1.7')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (7, 'score', '1.2')" );

		$decimal_rows = $driver->query(
			"SELECT post_id FROM wptests_postmeta WHERE meta_key = 'score' AND meta_value < 1.5 ORDER BY post_id"
		);

		$this->assertSame(
			array( '2', '3', '7' ),
			array_map(
				static function ( $row ): string {
					return $row->post_id;
				},
				$decimal_rows
			)
		);
		$this->assertStringContainsString( $meta_value_cast_sql . ' < 1.5', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests text metadata columns use MySQL numeric coercion for ORDER BY column + 0.
	 */
	public function test_text_metadata_plus_zero_order_by_uses_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'score', '10')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'score', '2')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, 'score', 'abc')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (4, 'score', '')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (5, 'decimal', '1.7')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (6, 'decimal', '1.2')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (7, 'decimal', '2')" );

		$rows = $driver->query(
			"SELECT post_id FROM wptests_postmeta WHERE meta_key = 'score' ORDER BY meta_value+0 ASC, post_id ASC"
		);

		$this->assertSame(
			array( '3', '4', '2', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->post_id;
				},
				$rows
			)
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'meta_value' );
		$this->assertStringContainsString(
			'ORDER BY ' . $meta_value_cast_sql . ' ASC, post_id ASC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$decimal_rows = $driver->query(
			"SELECT post_id FROM wptests_postmeta WHERE meta_key = 'decimal' ORDER BY meta_value+0 ASC, post_id ASC"
		);

		$this->assertSame(
			array( '6', '5', '7' ),
			array_map(
				static function ( $row ): string {
					return $row->post_id;
				},
				$decimal_rows
			)
		);
		$this->assertStringContainsString(
			'ORDER BY ' . $meta_value_cast_sql . ' ASC, post_id ASC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests DISTINCT ORDER BY rewrites keep numeric metadata ordering safe.
	 */
	public function test_distinct_text_metadata_plus_zero_order_by_uses_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_terms (
				`term_id` bigint(20) unsigned NOT NULL,
				`name` varchar(200) NOT NULL DEFAULT "",
				PRIMARY KEY (`term_id`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_termmeta (
				`meta_id` bigint(20) unsigned NOT NULL,
				`term_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_terms (`term_id`, `name`) VALUES (1, 'one')" );
		$driver->query( "INSERT INTO wptests_terms (`term_id`, `name`) VALUES (2, 'two')" );
		$driver->query( "INSERT INTO wptests_terms (`term_id`, `name`) VALUES (3, 'three')" );
		$driver->query(
			"INSERT INTO wptests_termmeta (`meta_id`, `term_id`, `meta_key`, `meta_value`) VALUES (1, 1, 'score', '10')"
		);
		$driver->query(
			"INSERT INTO wptests_termmeta (`meta_id`, `term_id`, `meta_key`, `meta_value`) VALUES (2, 2, 'score', '2')"
		);
		$driver->query(
			"INSERT INTO wptests_termmeta (`meta_id`, `term_id`, `meta_key`, `meta_value`) VALUES (3, 3, 'score', 'abc')"
		);

		$rows = $driver->query(
			"SELECT DISTINCT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_termmeta ON ( t.term_id = wptests_termmeta.term_id )
			WHERE wptests_termmeta.meta_key = 'score'
			ORDER BY wptests_termmeta.meta_value+0 ASC"
		);

		$this->assertSame(
			array( '3', '2', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->term_id;
				},
				$rows
			)
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'wptests_termmeta.meta_value' );
		$this->assertStringContainsString(
			'MIN(' . $meta_value_cast_sql . ') AS "__wp_pg_order_0"',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests ambiguous unqualified integer references do not guess a table.
	 */
	public function test_ambiguous_unqualified_integer_reference_fails_closed(): void {
		$driver = $this->create_driver();

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_left_ids (
				`ID` bigint(20) NOT NULL,
				`label` varchar(20) NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_right_ids (
				`ID` bigint(20) NOT NULL,
				`label` varchar(20) NOT NULL
			)'
		);

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT * FROM wptests_left_ids, wptests_right_ids WHERE ID = 'abc'"
		);

		$this->assertSame( 'SELECT * FROM wptests_left_ids, wptests_right_ids WHERE "ID" = \'abc\'', $sql );
		$this->assertStringNotContainsString( 'SUBSTRING(CAST', $sql );
	}

	/**
	 * Tests MySQL SIGNED and UNSIGNED casts coerce text safely for PostgreSQL.
	 */
	public function test_signed_and_unsigned_casts_coerce_mysql_text_values_for_postgresql(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query( 'CREATE TABLE wptests_postmeta (post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );

		$values = array(
			array( 1, '' ),
			array( 2, '   ' ),
			array( 3, 'abc' ),
			array( 4, '10abc' ),
			array( 5, '-7xyz' ),
			array( 6, '+8' ),
			array( 7, '42' ),
			array( 8, '+' ),
			array( 9, '-' ),
			array( 10, '  15xyz' ),
		);

		foreach ( $values as $value ) {
			$driver->query(
				sprintf(
					'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (%d, \'score\', %s)',
					$value[0],
					$driver->get_connection()->quote( $value[1] )
				)
			);
		}

		$select = 'SELECT post_id, meta_value, CAST(meta_value AS SIGNED) AS signed_value, CAST(meta_value AS UNSIGNED) AS unsigned_value FROM wptests_postmeta ORDER BY post_id';
		$rows   = $driver->query( $select );

		$this->assertSame(
			array(
				array( '', '0', '0' ),
				array( '   ', '0', '0' ),
				array( 'abc', '0', '0' ),
				array( '10abc', '10', '10' ),
				array( '-7xyz', '-7', '-7' ),
				array( '+8', '8', '8' ),
				array( '42', '42', '42' ),
				array( '+', '0', '0' ),
				array( '-', '0', '0' ),
				array( '  15xyz', '15', '15' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->meta_value, $row->signed_value, $row->unsigned_value );
				},
				$rows
			)
		);

		$meta_value_cast_sql = $this->get_expected_mysql_integer_cast_sql( 'meta_value' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT post_id, meta_value, ' . $meta_value_cast_sql . ' AS signed_value, ' . $meta_value_cast_sql . ' AS unsigned_value FROM wptests_postmeta ORDER BY post_id',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$select = "SELECT post_id, meta_value FROM wptests_postmeta WHERE meta_key = 'score' AND CAST(meta_value AS SIGNED) > 0 ORDER BY CAST(meta_value AS UNSIGNED INTEGER) DESC, post_id ASC";
		$rows   = $driver->query( $select );

		$this->assertSame(
			array( '42', '  15xyz', '10abc', '+8' ),
			array_map(
				static function ( $row ): string {
					return $row->meta_value;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => "SELECT post_id, meta_value FROM wptests_postmeta WHERE meta_key = 'score' AND " . $meta_value_cast_sql . ' > 0 ORDER BY ' . $meta_value_cast_sql . ' DESC, post_id ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests lowercase signed integer casts trigger PostgreSQL compatibility translation.
	 */
	public function test_lowercase_signed_integer_cast_triggers_postgresql_rewrite(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$rows = $driver->query( "SELECT cast('7' as signed integer) AS cast_value" );

		$this->assertSame( '7', $rows[0]->cast_value );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT ' . $this->get_expected_mysql_integer_cast_sql( "'7'" ) . ' AS cast_value',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests RAND() and RAND(seed) are translated without mutating session seed state.
	 */
	public function test_rand_functions_are_translated_to_postgresql_random(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_links (link_id INTEGER PRIMARY KEY)' );
		$driver->query( 'INSERT INTO wptests_links (link_id) VALUES (1)' );
		$driver->query( 'INSERT INTO wptests_links (link_id) VALUES (2)' );

		$rows = $driver->query( 'SELECT link_id FROM wptests_links ORDER BY RAND(7) LIMIT 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT link_id FROM wptests_links ORDER BY random() LIMIT 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$driver->query( 'SELECT rand() AS random_value' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT random() AS random_value',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL-only expression names inside string literals are not rewritten.
	 */
	public function test_expression_rewrite_does_not_replace_string_literals(): void {
		$driver = $this->create_driver();

		$select = "SELECT 'FIELD(ID, 1)', 'CAST(meta_value AS SIGNED)', 'CAST(meta_value AS UNSIGNED)', 'RAND()' AS literal_value";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			"SELECT 'FIELD(ID, 1)', 'CAST(meta_value AS SIGNED)', 'CAST(meta_value AS UNSIGNED)', 'RAND()' AS literal_value",
			$sql
		);
	}

	/**
	 * Tests SELECT DISTINCT term ID queries hide ORDER BY expressions.
	 */
	public function test_distinct_term_id_order_by_name_preserves_visible_projection_with_limit(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Beta')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Alpha')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (10, 1, 'category')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (20, 2, 'category')" );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (1, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (1, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (1, 20)' );

		$select = "SELECT DISTINCT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('category') AND tr.object_id IN (1)
			ORDER BY t.name ASC
			LIMIT 10";
		$rows   = $driver->query( $select );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2', $rows[0]->term_id );
		$this->assertSame( '1', $rows[1]->term_id );
		$this->assertSame( array( 'term_id' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "__wp_pg_distinct"."term_id" AS "term_id" FROM (SELECT t.term_id AS "term_id", MIN(t.name) AS "__wp_pg_order_0" FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN (\'category\') AND tr.object_id IN (1) GROUP BY t.term_id) AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 10',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests SELECT DISTINCT term ID queries hide relationship order columns.
	 */
	public function test_distinct_term_id_order_by_term_order_preserves_visible_projection(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, term_order INTEGER NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Beta')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Alpha')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (10, 1, 'category')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (20, 2, 'category')" );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES (1, 10, 2)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES (1, 20, 1)' );

		$rows = $driver->query(
			"SELECT DISTINCT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('category') AND tr.object_id IN (1)
			ORDER BY tr.term_order ASC
			LIMIT 100"
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( '2', $rows[0]->term_id );
		$this->assertSame( '1', $rows[1]->term_id );
		$this->assertSame( array( 'term_id' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "__wp_pg_distinct"."term_id" AS "term_id" FROM (SELECT t.term_id AS "term_id", MIN(tr.term_order) AS "__wp_pg_order_0" FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN (\'category\') AND tr.object_id IN (1) GROUP BY t.term_id) AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 100',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests parenthesized user ID DISTINCT queries keep user_login ordering hidden.
	 */
	public function test_distinct_parenthesized_user_id_order_by_hides_login_order_column(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, user_login TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (1, \'zeta\')' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (2, \'alpha\')' );

		$rows = $driver->query( 'SELECT DISTINCT(wptests_users.ID) FROM wptests_users WHERE 1=1  ORDER BY user_login LIMIT 0, 50' );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( '1', $rows[1]->ID );
		$this->assertSame( array( 'ID' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "__wp_pg_distinct"."ID" AS "ID" FROM (SELECT (wptests_users."ID") AS "ID", MIN(user_login) AS "__wp_pg_order_0" FROM wptests_users WHERE 1 = 1 GROUP BY (wptests_users."ID")) AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 50 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests unsupported DISTINCT SELECT modifiers do not enter the grouped rewrite.
	 */
	public function test_distinct_order_by_unsupported_select_modifier_fails_closed(): void {
		$driver    = $this->create_driver();
		$modifiers = array(
			'HIGH_PRIORITY',
			'SQL_BIG_RESULT',
			'SQL_BUFFER_RESULT',
			'SQL_CACHE',
			'SQL_NO_CACHE',
			'SQL_SMALL_RESULT',
			'STRAIGHT_JOIN',
		);

		foreach ( $modifiers as $modifier ) {
			$sql = $this->translate_driver_query_with_private_method(
				$driver,
				'translate_distinct_order_by_query',
				sprintf(
					'SELECT DISTINCT %s t.term_id FROM wptests_terms AS t ORDER BY t.name ASC',
					$modifier
				)
			);

			$this->assertNull( $sql, sprintf( '%s should fall through unchanged.', $modifier ) );
		}

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_distinct_order_by_query',
			'SELECT DISTINCT SQL_CALC_FOUND_ROWS HIGH_PRIORITY t.term_id FROM wptests_terms AS t ORDER BY t.name ASC'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests keyword-like DISTINCT projection aliases are matched in ORDER BY.
	 */
	public function test_distinct_order_by_keyword_projection_alias_preserves_projected_order(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY)' );
		$driver->query( 'INSERT INTO wptests_users ("ID") VALUES (2023)' );
		$driver->query( 'INSERT INTO wptests_users ("ID") VALUES (2024)' );

		$rows = $driver->query( 'SELECT DISTINCT ID AS year FROM wptests_users ORDER BY year DESC' );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2024', $rows[0]->year );
		$this->assertSame( '2023', $rows[1]->year );
		$this->assertSame( array( 'year' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT DISTINCT "ID" AS year FROM wptests_users ORDER BY year DESC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests date archive aliases can satisfy DISTINCT ORDER BY references.
	 */
	public function test_distinct_date_archive_keyword_projection_aliases_match_order_by_items(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_distinct_order_by_query',
			'SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
			FROM wptests_posts
			ORDER BY year DESC, month DESC'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests date archive DISTINCT queries order by hidden aggregate post dates.
	 */
	public function test_distinct_date_archive_order_by_uses_hidden_aggregate_sort_column(): void {
		$driver = $this->create_driver();

		$select = "SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
			FROM wptests_posts
			WHERE post_type = 'foo'
			AND post_status != 'auto-draft' AND post_status != 'trash'
			ORDER BY post_date DESC";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_distinct_order_by_query', $select );

		$year_sql  = $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' );
		$month_sql = $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' );
		$this->assertSame(
			'SELECT "__wp_pg_distinct"."year" AS "year", "__wp_pg_distinct"."month" AS "month" FROM (SELECT ' . $year_sql . ' AS "year", ' . $month_sql . ' AS "month", MAX(post_date) AS "__wp_pg_order_0" FROM wptests_posts WHERE post_type = \'foo\' AND post_status != \'auto-draft\' AND post_status != \'trash\' GROUP BY ' . $year_sql . ', ' . $month_sql . ') AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" DESC',
			$sql
		);

		$outer_projection = substr( $sql, 0, strpos( $sql, ' FROM (' ) );
		$this->assertStringNotContainsString( '__wp_pg_order_0', $outer_projection );
		$this->assertStringNotContainsString( 'SELECT DISTINCT', $sql );
	}

	/**
	 * Tests SQL_CALC_FOUND_ROWS SELECT queries are translated for PostgreSQL.
	 */
	public function test_sql_calc_found_rows_select_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (1, 'post', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (2, 'post', 'publish', '2024-01-02 00:00:00')" );

		$select = "SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE 1 = 1 AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 1";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts."ID" FROM wptests_posts WHERE 1 = 1 AND ((wptests_posts.post_type = \'post\' AND (wptests_posts.post_status = \'publish\'))) ORDER BY wptests_posts.post_date DESC LIMIT 1 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests FOUND_ROWS returns the last SQL_CALC_FOUND_ROWS result count.
	 */
	public function test_found_rows_returns_last_sql_calc_found_rows_count(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (1, 'post', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (2, 'post', 'publish')" );

		$driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post'
			ORDER BY wptests_posts.ID ASC
			LIMIT 0, 2"
		);
		$rows = $driver->query( 'SELECT FOUND_ROWS()' );

		$this->assertSame( '2', $rows[0]->{'FOUND_ROWS()'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests DISTINCT SQL_CALC_FOUND_ROWS queries strip the modifier before PostgreSQL.
	 */
	public function test_distinct_sql_calc_found_rows_select_strips_modifier_and_orders_safely(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY, user_login TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_usermeta (user_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (1, \'zeta\')' );
		$driver->query( 'INSERT INTO wptests_users ("ID", user_login) VALUES (2, \'alpha\')' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (1, \'foo\', \'bar\')' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (1, \'foo\', \'baz\')' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (2, \'foo\', \'bar\')' );

		$select = "SELECT DISTINCT SQL_CALC_FOUND_ROWS wptests_users.ID
			FROM wptests_users INNER JOIN wptests_usermeta ON ( wptests_users.ID = wptests_usermeta.user_id )
			WHERE 1=1 AND wptests_usermeta.meta_key = 'foo'
			ORDER BY user_login ASC
			LIMIT 0, 10";
		$rows   = $driver->query( $select );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( '1', $rows[1]->ID );
		$this->assertSame( array( 'ID' ), array_keys( get_object_vars( $rows[0] ) ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $queries[0]['sql'] );
		$this->assertSame(
			'SELECT "__wp_pg_distinct"."ID" AS "ID" FROM (SELECT wptests_users."ID" AS "ID", MIN(user_login) AS "__wp_pg_order_0" FROM wptests_users INNER JOIN wptests_usermeta ON (wptests_users."ID" = wptests_usermeta.user_id) WHERE 1 = 1 AND wptests_usermeta.meta_key = \'foo\' GROUP BY wptests_users."ID") AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 10 OFFSET 0',
			$queries[0]['sql']
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '2', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests SQL_CALC_FOUND_ROWS grouped postmeta queries aggregate sort expressions.
	 */
	public function test_sql_calc_grouped_postmeta_order_by_uses_aggregate_sort_expressions(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) NOT NULL DEFAULT "",
				`post_status` varchar(20) NOT NULL DEFAULT "",
				`post_date` datetime NOT NULL DEFAULT "0000-00-00 00:00:00",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (1, 'post', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (2, 'post', 'publish', '2024-01-02 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (3, 'post', 'publish', '2024-01-03 00:00:00')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'foo', 'b')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'foo', 'a')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, 'foo', 'a')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'bar', '5')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'bar', '2')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, 'bar', '9')" );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
				INNER JOIN wptests_postmeta AS mt1 ON ( wptests_posts.ID = mt1.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'foo'
				AND mt1.meta_key = 'bar'
			GROUP BY wptests_posts.ID
			ORDER BY CAST(wptests_postmeta.meta_value AS CHAR) ASC, CAST(mt1.meta_value AS UNSIGNED) DESC
			LIMIT 0, 10"
		);

		$this->assertSame(
			array( '3', '2', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$rows
			)
		);

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $sql );
		$this->assertStringContainsString( 'ORDER BY MIN(CAST(wptests_postmeta.meta_value AS text)) ASC', $sql );
		$this->assertStringContainsString( 'MAX(' . $this->get_expected_mysql_integer_cast_sql( 'mt1.meta_value' ) . ') DESC', $sql );

		$numeric_rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'bar'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_postmeta.meta_value+0 ASC
			LIMIT 0, 10"
		);

		$this->assertSame(
			array( '2', '1', '3' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$numeric_rows
			)
		);
		$this->assertStringContainsString(
			'ORDER BY MIN(' . $this->get_expected_mysql_numeric_cast_sql( 'wptests_postmeta.meta_value' ) . ') ASC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$decimal_rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'bar'
			GROUP BY wptests_posts.ID
			ORDER BY CAST(wptests_postmeta.meta_value AS DECIMAL(10, 2)) DESC
			LIMIT 0, 10"
		);

		$this->assertSame(
			array( '3', '1', '2' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$decimal_rows
			)
		);
		$this->assertStringContainsString(
			'ORDER BY MAX(CAST (wptests_postmeta.meta_value AS DECIMAL (10, 2))) DESC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '3', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests numeric literals in predicate context use MySQL truthiness.
	 */
	public function test_numeric_literal_predicates_use_mysql_truthiness_without_changing_values_or_limits(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_date TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (1, \'2024-01-01 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (7, \'2024-01-07 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (9, \'2024-01-09 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (10, \'2024-01-10 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (11, \'2024-01-11 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_date) VALUES (12, \'2024-01-12 00:00:00\')' );

		$rows = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE 1=1 AND 0
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10'
		);

		$this->assertSame( array(), $rows );
		$this->assertStringContainsString( 'WHERE 1 = 1 AND (0 <> 0)', $driver->get_last_postgresql_queries()[0]['sql'] );

		$between_rows = $driver->query( 'SELECT ID FROM wptests_posts WHERE ID BETWEEN 9 AND 11 ORDER BY ID ASC' );
		$this->assertSame(
			array( '9', '10', '11' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$between_rows
			)
		);
		$this->assertStringContainsString( 'WHERE "ID" BETWEEN 9 AND 11', $driver->get_last_postgresql_queries()[0]['sql'] );
		$this->assertStringNotContainsString( '(11 <> 0)', $driver->get_last_postgresql_queries()[0]['sql'] );

		$date_between_sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			'SELECT ID FROM wptests_posts WHERE DAYOFMONTH(post_date) BETWEEN 9 AND 11'
		);
		$this->assertStringContainsString( ' BETWEEN 9 AND 11', $date_between_sql );
		$this->assertStringNotContainsString( '(11 <> 0)', $date_between_sql );

		$in_rows = $driver->query( 'SELECT ID FROM wptests_posts WHERE ID IN (7) ORDER BY ID ASC' );
		$this->assertCount( 1, $in_rows );
		$this->assertSame( '7', $in_rows[0]->ID );
		$this->assertStringContainsString( 'WHERE "ID" IN (7)', $driver->get_last_postgresql_queries()[0]['sql'] );
		$this->assertStringNotContainsString( '(7 <> 0)', $driver->get_last_postgresql_queries()[0]['sql'] );

		$selected_zero = $driver->query( 'SELECT 0' );
		$this->assertSame( '0', $selected_zero[0]->{'0'} );
		$this->assertSame( 'SELECT 0', $driver->get_last_postgresql_queries()[0]['sql'] );

		$limit_zero = $driver->query( 'SELECT ID FROM wptests_posts ORDER BY ID LIMIT 0' );
		$this->assertSame( array(), $limit_zero );
		$this->assertSame( 'SELECT "ID" FROM wptests_posts ORDER BY "ID" LIMIT 0', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests correlated subquery identifiers resolve through table metadata casing.
	 */
	public function test_correlated_subquery_post_id_identifier_uses_metadata_casing(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) NOT NULL DEFAULT "",
				`post_status` varchar(20) NOT NULL DEFAULT "",
				`post_date` datetime NOT NULL DEFAULT "0000-00-00 00:00:00",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (1, 'post', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (2, 'post', 'publish', '2024-01-02 00:00:00')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'target', 'abc')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'target', 'abc')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'blocked', '1')" );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND (
					NOT EXISTS (
						SELECT 1 FROM wptests_postmeta mt1
						WHERE mt1.post_ID = wptests_postmeta.post_ID
							AND mt1.meta_key = 'blocked'
						LIMIT 1
					)
					AND wptests_postmeta.meta_value = 'abc'
				)
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString( 'mt1.post_id = wptests_postmeta.post_id', $sql );
		$this->assertStringNotContainsString( 'post_ID', $sql );
		$this->assertStringContainsString( 'wptests_posts."ID"', $sql );
	}

	/**
	 * Tests DECIMAL casts use text only for LIKE predicates.
	 */
	public function test_decimal_cast_like_uses_text_without_changing_numeric_comparisons(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) NOT NULL DEFAULT "",
				`post_status` varchar(20) NOT NULL DEFAULT "",
				`post_date` datetime NOT NULL DEFAULT "0000-00-00 00:00:00",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (1, 'post', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (2, 'post', 'publish', '2024-01-02 00:00:00')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'decimal_value', '10.30')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'decimal_value', '10.40')" );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'decimal_value'
				AND CAST(wptests_postmeta.meta_value AS DECIMAL(10,2)) LIKE '%.3%'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertStringContainsString( 'AS text) LIKE', $driver->get_last_postgresql_queries()[0]['sql'] );

		$numeric_rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'decimal_value'
				AND CAST(wptests_postmeta.meta_value AS DECIMAL(10,2)) > 10.35
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);

		$this->assertCount( 1, $numeric_rows );
		$this->assertSame( '2', $numeric_rows[0]->ID );
		$this->assertStringNotContainsString( 'AS text) >', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests grouped DISTINCT ORDER BY shapes fail closed for later SELECT passes.
	 */
	public function test_distinct_order_by_grouped_shape_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_distinct_order_by_query',
			'SELECT DISTINCT t.term_id, COUNT(*) AS term_tt_count FROM wptests_terms AS t GROUP BY t.term_id ORDER BY t.name ASC'
		);

		$this->assertNull( $sql );

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_distinct_order_by_query',
			'SELECT DISTINCT wptests_users.ID FROM wptests_users WHERE wptests_users.ID IN (SELECT user_id FROM wptests_usermeta) ORDER BY user_login ASC'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests grouped HAVING predicates can reference aggregate projection aliases.
	 */
	public function test_grouped_having_aggregate_alias_is_translated_for_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Parent')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Single')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (10, 1, 'category')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (11, 1, 'post_tag')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (12, 2, 'category')" );

		$rows = $driver->query(
			'SELECT tt.term_id, t.*, count(*) as term_tt_count FROM wptests_term_taxonomy tt
			LEFT JOIN wptests_terms t ON t.term_id = tt.term_id
			GROUP BY t.term_id
			HAVING term_tt_count > 1
			LIMIT 1'
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', (string) $rows[0]->term_id );
		$this->assertSame( '2', (string) $rows[0]->term_tt_count );

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString( 'GROUP BY t.term_id, tt.term_id', $sql );
		$this->assertStringContainsString( 'HAVING (count (*)) > 1', $sql );
		$this->assertStringNotContainsString( 'HAVING term_tt_count', $sql );
	}

	/**
	 * Tests grouped HAVING identifiers that are not aliases fail closed.
	 */
	public function test_grouped_having_non_alias_identifier_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			'SELECT term_id, COUNT(*) AS term_tt_count FROM wptests_term_taxonomy GROUP BY term_id HAVING missing_alias > 1'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests non-grouped non-aggregate projection aliases are not rewritten in HAVING.
	 */
	public function test_grouped_having_unsupported_projection_alias_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			"SELECT term_id, name AS term_name FROM wptests_terms GROUP BY term_id HAVING term_name = 'Parent'"
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests scalar COUNT queries drop irrelevant ORDER BY clauses.
	 */
	public function test_aggregate_count_order_by_is_dropped_for_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, comment_date_gmt TEXT NOT NULL, comment_approved TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date_gmt, comment_approved) VALUES (1, \'2024-01-03 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date_gmt, comment_approved) VALUES (2, \'2024-01-01 00:00:00\', \'0\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date_gmt, comment_approved) VALUES (3, \'2024-01-02 00:00:00\', \'spam\')' );

		$rows = $driver->query(
			"SELECT COUNT(*)
			FROM wptests_comments
			WHERE comment_approved IN ('0', '1')
			ORDER BY wptests_comments.comment_date_gmt ASC
			LIMIT 0,3"
		);

		$this->assertSame( '2', array_values( get_object_vars( $rows[0] ) )[0] );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT COUNT (*) FROM wptests_comments WHERE comment_approved IN (\'0\', \'1\') LIMIT 3 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests grouped date archive queries order by an aggregate post date.
	 */
	public function test_grouped_date_archive_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$select = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts
			FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY YEAR(post_date), MONTH(post_date)
			ORDER BY post_date DESC";
		$sql    = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			$select
		);

		$year_sql  = $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' );
		$month_sql = $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' );
		$this->assertSame(
			'SELECT ' . $year_sql . ' AS "year", ' . $month_sql . ' AS "month", count ("ID") as posts FROM wptests_posts WHERE post_type = \'post\' AND post_status = \'publish\' GROUP BY ' . $year_sql . ', ' . $month_sql . ' ORDER BY MAX(post_date) DESC',
			$sql
		);
		$this->assertStringNotContainsString( 'post_date DESC', str_replace( 'MAX(post_date) DESC', '', $sql ) );
	}

	/**
	 * Tests yearly grouped archive queries order by an aggregate post date.
	 */
	public function test_grouped_year_archive_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$select = "SELECT YEAR(post_date) AS `year`, count(ID) as posts
			FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY YEAR(post_date)
			ORDER BY post_date DESC";
		$sql    = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			$select
		);

		$year_sql = $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' );
		$this->assertSame(
			'SELECT ' . $year_sql . ' AS "year", count ("ID") as posts FROM wptests_posts WHERE post_type = \'post\' AND post_status = \'publish\' GROUP BY ' . $year_sql . ' ORDER BY MAX(post_date) DESC',
			$sql
		);
		$this->assertStringNotContainsString( 'post_date DESC', str_replace( 'MAX(post_date) DESC', '', $sql ) );
	}

	/**
	 * Tests unsupported DISTINCT grouped archive queries fail closed.
	 */
	public function test_distinct_count_grouped_year_archive_order_by_fails_closed(): void {
		$driver = $this->create_driver();

		$select = "SELECT DISTINCT count(ID) AS posts
			FROM wptests_posts
			WHERE post_type = 'post'
			GROUP BY YEAR(post_date)
			ORDER BY post_date DESC";
		$sql    = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			$select
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests weekly grouped DISTINCT archive queries order by an aggregate post date.
	 */
	public function test_grouped_week_archive_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$select = "SELECT DISTINCT WEEK( `post_date`, 1 ) AS `week`, YEAR( `post_date` ) AS `yr`, DATE_FORMAT( `post_date`, '%Y-%m-%d' ) AS `yyyymmdd`, count( `ID` ) AS `posts`
			FROM `wptests_posts`
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY WEEK( `post_date`, 1 ), YEAR( `post_date` )
			ORDER BY `post_date` DESC";
		$sql    = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			$select
		);

		$week_sql = $this->get_expected_mysql_week_mode_one_sql( '"post_date"' );
		$year_sql = $this->get_expected_zero_date_safe_extract_sql( 'YEAR', '"post_date"' );
		$date_sql = $this->get_expected_mysql_date_format_sql( '%Y-%m-%d', 'MAX("post_date")' );
		$this->assertSame(
			'SELECT ' . $week_sql . ' AS "week", ' . $year_sql . ' AS "yr", ' . $date_sql . ' AS "yyyymmdd", count ("ID") AS "posts" FROM "wptests_posts" WHERE post_type = \'post\' AND post_status = \'publish\' GROUP BY ' . $week_sql . ', ' . $year_sql . ' ORDER BY MAX("post_date") DESC',
			$sql
		);
		$this->assertStringNotContainsString( 'SELECT DISTINCT', $sql );
		$this->assertStringNotContainsString( 'WEEK', $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );
	}

	/**
	 * Tests daily grouped archive queries order by an aggregate post date.
	 */
	public function test_grouped_day_archive_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$select = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, DAYOFMONTH(post_date) AS `dayofmonth`, count(ID) as posts
			FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY YEAR(post_date), MONTH(post_date), DAYOFMONTH(post_date)
			ORDER BY post_date DESC";
		$sql    = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			$select
		);

		$year_sql  = $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' );
		$month_sql = $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' );
		$day_sql   = $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' );
		$this->assertSame(
			'SELECT ' . $year_sql . ' AS "year", ' . $month_sql . ' AS "month", ' . $day_sql . ' AS "dayofmonth", count ("ID") as posts FROM wptests_posts WHERE post_type = \'post\' AND post_status = \'publish\' GROUP BY ' . $year_sql . ', ' . $month_sql . ', ' . $day_sql . ' ORDER BY MAX(post_date) DESC',
			$sql
		);
		$this->assertStringNotContainsString( 'post_date DESC', str_replace( 'MAX(post_date) DESC', '', $sql ) );
	}

	/**
	 * Tests grouped comment ID queries order by aggregate meta values.
	 */
	public function test_grouped_comment_meta_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY)' );
		$driver->query( 'CREATE TABLE wptests_commentmeta (comment_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID") VALUES (1)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID") VALUES (2)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID") VALUES (3)' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (1, \'foo\', \'aaa\')' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (2, \'foo\', \'zzz\')' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (3, \'foo\', \'jjj\')' );

		$rows = $driver->query(
			"SELECT wptests_comments.comment_ID
			FROM wptests_comments INNER JOIN wptests_commentmeta ON ( wptests_comments.comment_ID = wptests_commentmeta.comment_id )
			WHERE wptests_commentmeta.meta_key = 'foo'
			GROUP BY wptests_comments.comment_ID
			ORDER BY CAST(wptests_commentmeta.meta_value AS CHAR) DESC, wptests_comments.comment_ID DESC"
		);

		$this->assertSame(
			array( '2', '3', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->comment_ID;
				},
				$rows
			)
		);
		$this->assertSame( array( 'comment_ID' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_comments."comment_ID" FROM wptests_comments INNER JOIN wptests_commentmeta ON (wptests_comments."comment_ID" = wptests_commentmeta.comment_id) WHERE wptests_commentmeta.meta_key = \'foo\' GROUP BY wptests_comments."comment_ID" ORDER BY MAX(CAST(wptests_commentmeta.meta_value AS text)) DESC, wptests_comments."comment_ID" DESC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests grouped comment ID queries aggregate comment date secondary ordering.
	 */
	public function test_grouped_comment_meta_secondary_order_by_uses_aggregate_sort_expression(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, comment_date TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_commentmeta (comment_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date) VALUES (1, \'2015-01-28 03:00:00\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date) VALUES (2, \'2015-01-28 05:00:00\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", comment_date) VALUES (3, \'2015-01-28 03:00:00\')' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (1, \'foo\', \'jjj\')' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (2, \'foo\', \'zzz\')' );
		$driver->query( 'INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES (3, \'foo\', \'aaa\')' );

		$rows = $driver->query(
			"SELECT wptests_comments.comment_ID
			FROM wptests_comments INNER JOIN wptests_commentmeta ON ( wptests_comments.comment_ID = wptests_commentmeta.comment_id )
			WHERE wptests_commentmeta.meta_key = 'foo'
			GROUP BY wptests_comments.comment_ID
			ORDER BY wptests_comments.comment_date ASC, CAST(wptests_commentmeta.meta_value AS CHAR) ASC, wptests_comments.comment_ID ASC"
		);

		$this->assertSame(
			array( '3', '1', '2' ),
			array_map(
				static function ( $row ): string {
					return $row->comment_ID;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_comments."comment_ID" FROM wptests_comments INNER JOIN wptests_commentmeta ON (wptests_comments."comment_ID" = wptests_commentmeta.comment_id) WHERE wptests_commentmeta.meta_key = \'foo\' GROUP BY wptests_comments."comment_ID" ORDER BY MIN(wptests_comments.comment_date) ASC, MIN(CAST(wptests_commentmeta.meta_value AS text)) ASC, wptests_comments."comment_ID" ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests normal non-aggregate SELECT ORDER BY shapes do not enter the strict rewrite.
	 */
	public function test_strict_order_by_rewrite_ignores_normal_select_order_by(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_strict_aggregate_grouped_order_by_query',
			'SELECT comment_ID FROM wptests_comments ORDER BY comment_ID DESC'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests MySQL date/time extraction functions are translated for PostgreSQL.
	 */
	public function test_mysql_date_time_extract_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = 'SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, DAYOFMONTH(post_date) AS d, DAY(post_date) AS day_value, HOUR(post_date) AS h, MINUTE(post_date) AS i, SECOND(post_date) AS s, EXTRACT(DAY FROM post_date) AS extracted_day FROM wptests_posts WHERE ID = 1';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' ) . ' AS y, ' . $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' ) . ' AS m, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS d, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS day_value, ' . $this->get_expected_zero_date_safe_extract_sql( 'HOUR', 'post_date' ) . ' AS h, ' . $this->get_expected_zero_date_safe_extract_sql( 'MINUTE', 'post_date' ) . ' AS i, ' . $this->get_expected_zero_date_safe_extract_sql( 'SECOND', 'post_date' ) . ' AS s, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS extracted_day FROM wptests_posts WHERE "ID" = 1',
			$sql
		);
	}

	/**
	 * Tests generated date/time extraction SQL is safe for MySQL zero-date values.
	 */
	public function test_mysql_date_time_extract_functions_are_zero_date_safe_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = 'SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, DAYOFMONTH(post_date) AS d, HOUR(post_date) AS h FROM wptests_posts WHERE post_date = \'0000-00-00 00:00:00\'';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertStringContainsString( "CASE WHEN CAST(post_date AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST(post_date AS text) FROM 1 FOR 4) = '0000'", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST(post_date AS text) FROM 6 FOR 2) = '00'", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST(post_date AS text) FROM 9 FOR 2) = '00'", $sql );
		$this->assertStringContainsString( 'THEN CAST(SUBSTRING(CAST(post_date AS text) FROM 1 FOR 4) AS integer)', $sql );
		$this->assertStringContainsString( 'THEN CAST(SUBSTRING(CAST(post_date AS text) FROM 6 FOR 2) AS integer)', $sql );
		$this->assertStringContainsString( 'THEN CAST(SUBSTRING(CAST(post_date AS text) FROM 9 FOR 2) AS integer)', $sql );
		$this->assertStringContainsString( "THEN CASE WHEN CAST(post_date AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}' THEN CAST(SUBSTRING(CAST(post_date AS text) FROM 12 FOR 2) AS integer) ELSE 0 END", $sql );
		$this->assertStringNotContainsString( 'SELECT CAST(EXTRACT(YEAR FROM CAST(post_date AS timestamp)) AS integer) AS y', $sql );
		$this->assertStringContainsString( 'CAST(EXTRACT(YEAR FROM CAST(CASE WHEN CAST(post_date AS text)', $sql );
		$this->assertStringContainsString( 'THEN NULL ELSE CAST(post_date AS text) END AS timestamp)', $sql );
	}

	/**
	 * Tests literal MySQL zero-date extraction SQL guards the timestamp cast.
	 */
	public function test_mysql_date_time_extract_functions_guard_literal_zero_dates_for_postgresql(): void {
		$driver = $this->create_driver();

		$literals          = array(
			'0000-00-00 00:00:00',
			'0000-00-00',
			'2020-00-15 00:00:00',
			'2020-01-00 00:00:00',
			'2026-06-10 14:08:09',
		);
		$extract_functions = array(
			array(
				'name' => 'YEAR',
				'unit' => 'YEAR',
			),
			array(
				'name' => 'MONTH',
				'unit' => 'MONTH',
			),
			array(
				'name' => 'DAYOFMONTH',
				'unit' => 'DAY',
			),
			array(
				'name' => 'DAY',
				'unit' => 'DAY',
			),
			array(
				'name' => 'HOUR',
				'unit' => 'HOUR',
			),
			array(
				'name' => 'MINUTE',
				'unit' => 'MINUTE',
			),
			array(
				'name' => 'SECOND',
				'unit' => 'SECOND',
			),
		);

		foreach ( $literals as $literal ) {
			$expression_sql      = "'" . $literal . "'";
			$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

			foreach ( $extract_functions as $extract_function ) {
				$function_sql = sprintf( '%s(%s)', $extract_function['name'], $expression_sql );
				$sql          = $this->translate_driver_query_with_private_method(
					$driver,
					'translate_mysql_compatible_query',
					'SELECT ' . $function_sql . ' AS extracted_value'
				);
				$expected_sql = 'SELECT ' . $this->get_expected_zero_date_safe_extract_sql( $extract_function['unit'], $expression_sql ) . ' AS extracted_value';

				$this->assertSame(
					$expected_sql,
					$sql,
					$function_sql
				);
				$this->assertStringContainsString(
					'CAST(EXTRACT(' . $extract_function['unit'] . ' FROM CAST(CASE WHEN ' . $expression_text_sql,
					$sql,
					$function_sql
				);
				$this->assertStringContainsString(
					'THEN NULL ELSE ' . $expression_text_sql . ' END AS timestamp)',
					$sql,
					$function_sql
				);
				$this->assertStringNotContainsString(
					'CAST(EXTRACT(' . $extract_function['unit'] . ' FROM CAST(' . $expression_sql . ' AS timestamp))',
					$sql,
					$function_sql
				);
			}
		}

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT EXTRACT(DAY FROM '2020-01-00 00:00:00') AS extracted_value"
		);

		$this->assertSame(
			'SELECT ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', "'2020-01-00 00:00:00'" ) . ' AS extracted_value',
			$sql
		);
	}

	/**
	 * Tests MySQL WEEK and weekday index functions are translated for PostgreSQL.
	 */
	public function test_mysql_week_and_weekday_index_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = 'SELECT WEEK(post_date, 1) AS week_num, DAYOFWEEK(post_date) AS day_of_week, WEEKDAY(post_date) AS weekday_value FROM wptests_posts WHERE WEEK(post_date, 1) = 24 AND DAYOFWEEK(post_date) = 1 AND WEEKDAY(post_date) = 6';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_mysql_week_mode_one_sql( 'post_date' ) . ' AS week_num, ' . $this->get_expected_mysql_weekday_index_sql( 'dayofweek', 'post_date' ) . ' AS day_of_week, ' . $this->get_expected_mysql_weekday_index_sql( 'weekday', 'post_date' ) . ' AS weekday_value FROM wptests_posts WHERE ' . $this->get_expected_mysql_week_mode_one_sql( 'post_date' ) . ' = 24 AND ' . $this->get_expected_mysql_weekday_index_sql( 'dayofweek', 'post_date' ) . ' = 1 AND ' . $this->get_expected_mysql_weekday_index_sql( 'weekday', 'post_date' ) . ' = 6',
			$sql
		);
		$this->assertStringNotContainsString( 'WEEK(', $sql );
		$this->assertStringNotContainsString( 'DAYOFWEEK', $sql );
		$this->assertStringNotContainsString( 'WEEKDAY', $sql );
	}

	/**
	 * Tests lowercase MySQL date compatibility functions trigger translation.
	 */
	public function test_lowercase_mysql_date_compatibility_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT week(post_date, 1) AS week_num, dayofweek(post_date) AS day_of_week, weekday(post_date) AS weekday_value, date_format(post_date, '%Y-%m-%d') AS formatted_date FROM wptests_posts";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_mysql_week_mode_one_sql( 'post_date' ) . ' AS week_num, ' . $this->get_expected_mysql_weekday_index_sql( 'dayofweek', 'post_date' ) . ' AS day_of_week, ' . $this->get_expected_mysql_weekday_index_sql( 'weekday', 'post_date' ) . ' AS weekday_value, ' . $this->get_expected_mysql_date_format_sql( '%Y-%m-%d', 'post_date' ) . ' AS formatted_date FROM wptests_posts',
			$sql
		);
	}

	/**
	 * Tests supported MySQL DATE_FORMAT calls are translated for PostgreSQL.
	 */
	public function test_mysql_date_format_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_FORMAT(post_date, '%H.%i') AS hour_minute, DATE_FORMAT(post_date, '%Y-%m-%d') AS formatted_date FROM wptests_posts WHERE DATE_FORMAT(post_date, '%H.%i') >= 0.42";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_mysql_date_format_sql( '%H.%i', 'post_date' ) . ' AS hour_minute, ' . $this->get_expected_mysql_date_format_sql( '%Y-%m-%d', 'post_date' ) . ' AS formatted_date FROM wptests_posts WHERE ' . $this->get_expected_mysql_date_format_sql( '%H.%i', 'post_date' ) . ' >= 0.42',
			$sql
		);
		$this->assertStringContainsString( 'CAST(TO_CHAR(' . $this->get_expected_zero_date_safe_timestamp_sql( 'post_date' ) . ", 'HH24.MI') AS double precision)", $sql );
		$this->assertStringContainsString( 'TO_CHAR(' . $this->get_expected_zero_date_safe_timestamp_sql( 'post_date' ) . ", 'YYYY-MM-DD')", $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );
	}

	/**
	 * Tests date compatibility function names inside string literals are not translated.
	 */
	public function test_mysql_date_compatibility_function_names_inside_literals_are_not_translated(): void {
		$driver = $this->create_driver();

		$select = "SELECT 'WEEK(post_date, 1)' AS literal_week, 'DAYOFWEEK(post_date)' AS literal_day, 'DATE_FORMAT(post_date, %H.%i)' AS literal_format";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			"SELECT 'WEEK(post_date, 1)' AS literal_week, 'DAYOFWEEK(post_date)' AS literal_day, 'DATE_FORMAT(post_date, %H.%i)' AS literal_format",
			$sql
		);
		$this->assertStringNotContainsString( 'DATE_TRUNC', $sql );
		$this->assertStringNotContainsString( 'EXTRACT(DOW', $sql );
		$this->assertStringNotContainsString( 'TO_CHAR', $sql );
	}

	/**
	 * Tests generated WEEK, weekday, and DATE_FORMAT SQL guards zero-date timestamp casts.
	 */
	public function test_mysql_date_compatibility_functions_guard_zero_date_timestamp_casts_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT WEEK('0000-00-00 00:00:00', 1) AS week_num, DAYOFWEEK('2020-00-15 13:05:00') AS day_of_week, WEEKDAY('2020-01-00 13:05:00') AS weekday_value, DATE_FORMAT('0000-00-00 13:05:00', '%H.%i') AS hour_minute, DATE_FORMAT('2020-00-15 13:05:00', '%Y-%m-%d') AS formatted_date";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertStringContainsString( "CAST(CASE WHEN CAST('0000-00-00 00:00:00' AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", $sql );
		$this->assertStringContainsString( "CAST(CASE WHEN CAST('2020-00-15 13:05:00' AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", $sql );
		$this->assertStringContainsString( "CAST(CASE WHEN CAST('2020-01-00 13:05:00' AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", $sql );
		$this->assertStringContainsString( "THEN CAST(SUBSTRING(CAST('0000-00-00 13:05:00' AS text) FROM 12 FOR 2) || '.' || SUBSTRING(CAST('0000-00-00 13:05:00' AS text) FROM 15 FOR 2) AS double precision) ELSE 0 END", $sql );
		$this->assertStringContainsString( "THEN SUBSTRING(CAST('2020-00-15 13:05:00' AS text) FROM 1 FOR 10)", $sql );
		$this->assertStringNotContainsString( "CAST('0000-00-00 00:00:00' AS timestamp)", $sql );
		$this->assertStringNotContainsString( "CAST('2020-00-15 13:05:00' AS timestamp)", $sql );
		$this->assertStringNotContainsString( "CAST('2020-01-00 13:05:00' AS timestamp)", $sql );
	}

	/**
	 * Tests unsupported DATE_FORMAT specifiers remain unhandled.
	 */
	public function test_mysql_date_format_with_unsupported_specifier_remains_unhandled(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT DATE_FORMAT(post_date, '%W') AS formatted_date"
		);

		$this->assertSame(
			"SELECT DATE_FORMAT (post_date, '%W') AS formatted_date",
			$sql
		);
	}

	/**
	 * Tests representative WordPress date archive queries do not reach PostgreSQL with raw MySQL functions.
	 */
	public function test_wordpress_date_query_extract_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT post_id FROM wptests_postmeta, wptests_posts
			WHERE ID = post_id
			AND post_type = 'post'
			AND meta_key = '_wp_old_slug'
			AND meta_value = 'foo-bar'
			AND YEAR(post_date) = 2026
			AND MONTH(post_date) = 6
			AND DAYOFMONTH(post_date) = 10";

		$sql = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT post_id FROM wptests_postmeta, wptests_posts WHERE "ID" = post_id AND post_type = \'post\' AND meta_key = \'_wp_old_slug\' AND meta_value = \'foo-bar\' AND ' . $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' ) . ' = 2026 AND ' . $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' ) . ' = 6 AND ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' = 10',
			$sql
		);
	}

	/**
	 * Tests WordPress DATE_ADD queries are translated for PostgreSQL.
	 */
	public function test_wordpress_date_add_queries_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_ADD(comment_date_gmt, INTERVAL '0' SECOND) FROM wptests_comments WHERE comment_approved = '1' ORDER BY comment_date_gmt DESC LIMIT 1";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'comment_date_gmt', "'0'", 'second' ) . " FROM wptests_comments WHERE comment_approved = '1' ORDER BY comment_date_gmt DESC LIMIT 1",
			$sql
		);
		$this->assertStringNotContainsString( 'DATE_ADD', $sql );
	}

	/**
	 * Tests DATE_SUB queries are detected even without another rewrite trigger.
	 */
	public function test_mysql_date_sub_queries_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = 'SELECT DATE_SUB(post_date_gmt, INTERVAL 1 DAY) AS older';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_sql( '-', 'post_date_gmt', '1', 'day' ) . ' AS older',
			$sql
		);
		$this->assertStringNotContainsString( 'DATE_SUB', $sql );
	}

	/**
	 * Tests DATE_ADD supports the simple MySQL interval units used by WordPress.
	 */
	public function test_mysql_date_add_supports_simple_interval_units_for_postgresql(): void {
		$driver = $this->create_driver();
		$units  = array(
			'SECOND' => 'second',
			'MINUTE' => 'minute',
			'HOUR'   => 'hour',
			'DAY'    => 'day',
			'WEEK'   => 'week',
			'MONTH'  => 'month',
			'YEAR'   => 'year',
		);

		foreach ( $units as $mysql_unit => $postgresql_unit ) {
			$select = 'SELECT DATE_ADD(post_date_gmt, INTERVAL 2 ' . $mysql_unit . ') AS shifted';
			$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

			$this->assertSame(
				'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'post_date_gmt', '2', $postgresql_unit ) . ' AS shifted',
				$sql,
				$mysql_unit
			);
		}
	}

	/**
	 * Tests DATE_ADD parsing handles nested expressions and lowercase interval syntax.
	 */
	public function test_mysql_date_add_handles_nested_and_lowercase_interval_arguments(): void {
		$driver = $this->create_driver();

		$select = 'SELECT DATE_ADD(COALESCE(post_date_gmt, post_date), interval (1 + 1) day) AS shifted';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'COALESCE (post_date_gmt, post_date)', '(1 + 1)', 'day' ) . ' AS shifted',
			$sql
		);
	}

	/**
	 * Tests DATE_ADD timestamp casts are guarded for MySQL zero-date values.
	 */
	public function test_mysql_date_add_guards_zero_date_timestamp_casts_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_ADD('0000-00-00 00:00:00', INTERVAL 1 DAY) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', "'0000-00-00 00:00:00'", '1', 'day' ) . ' AS shifted',
			$sql
		);
		$this->assertStringContainsString( "CAST(CASE WHEN CAST('0000-00-00 00:00:00' AS text) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", $sql );
		$this->assertStringContainsString( "THEN NULL ELSE CAST('0000-00-00 00:00:00' AS text) END AS timestamp", $sql );
		$this->assertStringNotContainsString( "CAST('0000-00-00 00:00:00' AS timestamp)", $sql );
	}

	/**
	 * Tests unsupported DATE_ADD interval units fall through without semantic rewriting.
	 */
	public function test_mysql_date_add_with_unsupported_interval_unit_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			'SELECT DATE_ADD(post_date_gmt, INTERVAL 1 DAY_SECOND) AS shifted'
		);

		$this->assertNull( $sql );
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
	 * Tests SHOW FULL COLUMNS returns MySQL-shaped PostgreSQL catalog rows.
	 */
	public function test_show_full_columns_returns_mysql_shaped_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( 'SHOW FULL COLUMNS FROM `wptests_options`' );

		$this->assertCount( 4, $result );
		$this->assertSame( 'SHOW FULL COLUMNS FROM `wptests_options`', $driver->get_last_mysql_query() );
		$this->assertSame( 9, $driver->get_last_column_count() );
		$this->assertSame( 'Field', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'Collation', $driver->get_last_column_meta()[2]['name'] );
		$this->assertSame( 'Comment', $driver->get_last_column_meta()[8]['name'] );

		$this->assertSame( 'option_id', $result[0]->Field );
		$this->assertSame( 'bigint', $result[0]->Type );
		$this->assertNull( $result[0]->Collation );
		$this->assertSame( 'PRI', $result[0]->Key );
		$this->assertSame( 'auto_increment', $result[0]->Extra );
		$this->assertSame( 'select,insert,update,references', $result[0]->Privileges );
		$this->assertSame( '', $result[0]->Comment );

		$this->assertSame( 'option_name', $result[1]->Field );
		$this->assertSame( 'varchar(191)', $result[1]->Type );
		$this->assertSame( 'utf8mb4_unicode_ci', $result[1]->Collation );
		$this->assertSame( 'UNI', $result[1]->Key );

		$this->assertSame( 'option_value', $result[2]->Field );
		$this->assertSame( 'text', $result[2]->Type );
		$this->assertSame( 'utf8mb4_unicode_ci', $result[2]->Collation );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW FULL COLUMNS', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW COLUMNS accepts MySQL table qualification forms.
	 */
	public function test_show_columns_accepts_table_qualification_forms(): void {
		$cases = array(
			'SHOW COLUMNS IN wptests_options'           => array( 'public', 'wptests_options' ),
			'SHOW COLUMNS FROM public.wptests_options'  => array( 'public', 'wptests_options' ),
			'SHOW COLUMNS FROM wptests_options FROM public' => array( 'public', 'wptests_options' ),
			'SHOW COLUMNS IN wptests_options IN public' => array( 'public', 'wptests_options' ),
		);

		foreach ( $cases as $query => $params ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			$result = $driver->query( $query );

			$this->assertCount( 4, $result, $query );
			$this->assertSame( 'option_id', $result[0]->Field, $query );
			$this->assertSame( 'autoload', $result[3]->Field, $query );

			$queries = $driver->get_last_postgresql_queries();
			$this->assertCount( 1, $queries, $query );
			$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'], $query );
			$this->assertStringNotContainsString( 'SHOW COLUMNS', $queries[0]['sql'], $query );
			$this->assertSame( $params, $queries[0]['params'], $query );
		}
	}

	/**
	 * Tests SHOW COLUMNS LIKE filters catalog rows with bound parameters.
	 */
	public function test_show_columns_like_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW COLUMNS FROM wptests_options LIKE 'option_%'" );

		$this->assertCount( 3, $result );
		$this->assertSame( 'option_id', $result[0]->Field );
		$this->assertSame( 'option_name', $result[1]->Field );
		$this->assertSame( 'option_value', $result[2]->Field );
		$this->assertSame( 6, $driver->get_last_column_count() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name LIKE ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW COLUMNS', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_%' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW COLUMNS uses the same MySQL metadata rows as DESCRIBE.
	 */
	public function test_show_columns_uses_mysql_schema_metadata_like_describe(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_meta_columns (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lookup_key varchar(191) CHARACTER SET latin1 NOT NULL DEFAULT '',
				payload longtext COLLATE koi8r_general_ci NOT NULL,
				PRIMARY KEY (id),
				KEY lookup_key (lookup_key)
			)"
		);

		$describe = $driver->query( 'DESC wptests_meta_columns' );
		$show     = $driver->query( 'SHOW COLUMNS FROM wptests_meta_columns' );

		$this->assertEquals( $describe, $show );
		$this->assertSame( 'id', $show[0]->Field );
		$this->assertSame( 'PRI', $show[0]->Key );
		$this->assertSame( 'auto_increment', $show[0]->Extra );
		$this->assertSame( 'lookup_key', $show[1]->Field );
		$this->assertSame( 'varchar(191)', $show[1]->Type );
		$this->assertSame( 'MUL', $show[1]->Key );
		$this->assertSame( 'payload', $show[2]->Field );
		$this->assertSame( 'longtext', $show[2]->Type );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( '__wp_postgresql_mysql_column_metadata', $queries[0]['sql'] );
		$this->assertStringContainsString( '__wp_postgresql_mysql_index_metadata', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW COLUMNS', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_meta_columns' ), $queries[0]['params'] );

		$filtered = $driver->query( "SHOW COLUMNS FROM wptests_meta_columns LIKE 'lookup%'" );

		$this->assertCount( 1, $filtered );
		$this->assertSame( 'lookup_key', $filtered[0]->Field );
		$this->assertSame( 'MUL', $filtered[0]->Key );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name LIKE ?', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_meta_columns', 'lookup%' ), $queries[0]['params'] );

		$full = $driver->query( 'SHOW FULL COLUMNS FROM wptests_meta_columns' );

		$this->assertSame( 9, $driver->get_last_column_count() );
		$this->assertSame( 'id', $full[0]->Field );
		$this->assertNull( $full[0]->Collation );
		$this->assertSame( 'lookup_key', $full[1]->Field );
		$this->assertSame( 'latin1_swedish_ci', $full[1]->Collation );
		$this->assertSame( 'MUL', $full[1]->Key );
		$this->assertSame( 'payload', $full[2]->Field );
		$this->assertSame( 'koi8r_general_ci', $full[2]->Collation );
	}

	/**
	 * Tests DESCRIBE and SHOW COLUMNS preserve numeric precision and scale metadata.
	 */
	public function test_describe_preserves_numeric_precision_and_scale_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_numeric_meta (
				amount DECIMAL(10,2) NOT NULL,
				ratio NUMERIC(12,6),
				score FLOAT(10,3),
				measure DOUBLE(8,4)
			)'
		);

		$describe = $driver->query( 'DESC wptests_numeric_meta' );
		$show     = $driver->query( 'SHOW COLUMNS FROM wptests_numeric_meta' );

		$this->assertSame( 'decimal(10,2)', $describe[0]->Type );
		$this->assertSame( 'numeric(12,6)', $describe[1]->Type );
		$this->assertSame( 'float(10,3)', $describe[2]->Type );
		$this->assertSame( 'double(8,4)', $describe[3]->Type );
		$this->assertEquals( $describe, $show );
	}

	/**
	 * Tests CHANGE COLUMN without DEFAULT removes the backend and metadata defaults.
	 */
	public function test_change_column_without_default_drops_existing_default(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_defaults', 'BASE TABLE')"
		);
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.columns
				(table_schema, table_name, column_name, ordinal_position, data_type, character_maximum_length, collation_name, is_nullable, column_default, is_identity)
			VALUES
				('public', 'wptests_defaults', 'post_title', 1, 'character varying', 20, 'utf8mb4_unicode_ci', 'NO', '''stale''::character varying', 'NO')"
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_defaults (
				post_title varchar(20) NOT NULL DEFAULT 'stale'
			)"
		);

		$driver->query( 'ALTER TABLE wptests_defaults CHANGE COLUMN post_title post_title text NOT NULL' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_defaults" ALTER COLUMN "post_title" TYPE text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_defaults" ALTER COLUMN "post_title" SET NOT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_defaults" ALTER COLUMN "post_title" DROP DEFAULT',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$describe = $driver->query( 'DESC wptests_defaults' );
		$show     = $driver->query( 'SHOW COLUMNS FROM wptests_defaults' );

		$this->assertSame( 'post_title', $describe[0]->Field );
		$this->assertSame( 'text', $describe[0]->Type );
		$this->assertNull( $describe[0]->Default );
		$this->assertNull( $show[0]->Default );
	}

	/**
	 * Tests CHANGE COLUMN preserves existing identity DDL while updating MySQL metadata.
	 */
	public function test_change_column_auto_increment_integer_family_preserves_identity_ddl_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_identity', 'BASE TABLE')"
		);
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.columns
				(table_schema, table_name, column_name, ordinal_position, data_type, character_maximum_length, collation_name, is_nullable, column_default, is_identity)
			VALUES
				('public', 'wptests_identity', 'id', 1, 'bigint', NULL, NULL, 'NO', NULL, 'YES')"
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_identity (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				PRIMARY KEY (id)
			)'
		);

		$driver->query( 'ALTER TABLE wptests_identity CHANGE COLUMN `id` id int(11) NOT NULL AUTO_INCREMENT' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_identity" ALTER COLUMN "id" SET NOT NULL',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$describe = $driver->query( 'DESC wptests_identity' );
		$show     = $driver->query( 'SHOW COLUMNS FROM wptests_identity' );

		$this->assertEquals( $describe, $show );
		$this->assertSame( 'id', $show[0]->Field );
		$this->assertSame( 'int(11)', $show[0]->Type );
		$this->assertSame( 'NO', $show[0]->Null );
		$this->assertNull( $show[0]->Default );
		$this->assertSame( 'auto_increment', $show[0]->Extra );
	}

	/**
	 * Tests unsupported SHOW COLUMNS clauses do not fall through to the backend.
	 */
	public function test_show_columns_where_clause_does_not_reach_backend(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		try {
			$driver->query( "SHOW COLUMNS FROM wptests_options WHERE Field = 'option_name'" );
			$this->fail( 'Expected unsupported SHOW COLUMNS WHERE clause to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported SHOW COLUMNS statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
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
	 * Tests SHOW TABLES hides internal PostgreSQL metadata tables.
	 */
	public function test_show_tables_hides_internal_postgresql_metadata_tables(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', '__wp_postgresql_mysql_column_metadata', 'BASE TABLE'),
				('public', '__wp_postgresql_mysql_index_metadata', 'BASE TABLE'),
				('public', '__wp_postgresql_mysql_charset_metadata', 'BASE TABLE')"
		);

		$raw_catalog_tables = $driver->get_connection()->query(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = 'public'
				AND table_name IN (
					'__wp_postgresql_mysql_column_metadata',
					'__wp_postgresql_mysql_index_metadata',
					'__wp_postgresql_mysql_charset_metadata'
				)
			ORDER BY table_name"
		)->fetchAll( PDO::FETCH_COLUMN );

		$this->assertSame(
			array(
				'__wp_postgresql_mysql_charset_metadata',
				'__wp_postgresql_mysql_column_metadata',
				'__wp_postgresql_mysql_index_metadata',
			),
			$raw_catalog_tables
		);

		$tables = $driver->query( 'SHOW TABLES' );
		$names  = array_map(
			function ( $row ) {
				return $row->Tables_in_wptests;
			},
			$tables
		);

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts', 'wptests_view' ),
			$names
		);
	}

	/**
	 * Tests Site Health's information_schema.TABLES query returns rows for existing catalog tables only.
	 */
	public function test_information_schema_tables_site_health_query_returns_mysql_shape_with_single_quoted_aliases(): void {
		$driver = $this->create_driver( 'wordpress_develop_tests' );
		$this->install_information_schema_fixture( $driver );
		$this->install_site_health_table_count_fixture( $driver );

		$query = "SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows', SUM(data_length + index_length) as 'bytes'
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
				AND TABLE_NAME IN ('wptests_options','wptests_missing','wptests_posts')
			GROUP BY TABLE_NAME;";
		$rows  = $driver->query( $query );

		usort(
			$rows,
			static function ( $left, $right ): int {
				return strcmp( $left->table, $right->table );
			}
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'table', 'rows', 'bytes' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame(
			array(
				array( 'wptests_options', '2', '0' ),
				array( 'wptests_posts', '1', '0' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->table, $row->rows, $row->bytes );
				},
				$rows
			)
		);

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString( 'AS "table"', $sql );
		$this->assertStringContainsString( 'AS "rows"', $sql );
		$this->assertStringContainsString( 'AS "bytes"', $sql );
		$this->assertStringNotContainsString( "AS 'table'", $sql );
		$this->assertStringNotContainsString( "AS 'rows'", $sql );
		$this->assertStringNotContainsString( "AS 'bytes'", $sql );
		$this->assertStringContainsString( '"information_schema"."tables"', $sql );
		$this->assertStringContainsString( "\"TABLE_SCHEMA\" = 'wordpress_develop_tests'", $sql );
		$this->assertStringNotContainsString( '"wordpress_develop_tests"', $sql );
		$this->assertStringNotContainsString( 'FROM "wptests_missing"', $sql );
		$this->assertStringNotContainsString( 'FROM "public"."wptests_missing"', $sql );
	}

	/**
	 * Tests single-quoted aliases do not turn catalog predicate literals into identifiers.
	 */
	public function test_information_schema_tables_site_health_single_quoted_alias_preserves_predicate_string_literals(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_site_health_table_count_fixture( $driver );

		$query = "SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows', SUM(data_length + index_length) AS 'bytes'
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = 'wptests' AND TABLE_NAME = 'wptests_options'
			GROUP BY TABLE_NAME";
		$rows  = $driver->query( $query );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'wptests_options', $rows[0]->table );
		$this->assertSame( '2', $rows[0]->rows );
		$this->assertSame( '0', $rows[0]->bytes );

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString( 'AS "table"', $sql );
		$this->assertStringContainsString( "\"TABLE_SCHEMA\" = 'wptests'", $sql );
		$this->assertStringContainsString( "TABLE_NAME = 'wptests_options'", $sql );
		$this->assertStringNotContainsString( '"wptests"', $sql );
	}

	/**
	 * Tests unsupported information_schema.TABLES shapes do not enter the Site Health translator.
	 */
	public function test_information_schema_tables_site_health_unsupported_shapes_fail_closed(): void {
		$driver  = $this->create_driver();
		$queries = array(
			"SELECT COUNT(*) AS 'rows'
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = 'wptests' AND TABLE_NAME IN ('wptests_options')
				GROUP BY TABLE_NAME",
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows', SUM(data_length + index_length) AS 'bytes'
				FROM information_schema.TABLES
				WHERE TABLE_ROWS > 0
				GROUP BY TABLE_NAME",
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows', SUM(data_length + index_length) AS 'bytes'
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = 'wptests' AND TABLE_NAME IN ('wptests_options')
				GROUP BY TABLE_NAME
				ORDER BY TABLE_NAME",
		);

		foreach ( $queries as $query ) {
			$this->assertNull(
				$this->translate_driver_query_with_private_method(
					$driver,
					'translate_information_schema_tables_site_health_query',
					$query
				),
				$query
			);
		}
	}

	/**
	 * Tests SHOW INDEX returns MySQL-shaped PostgreSQL catalog rows.
	 */
	public function test_show_index_returns_mysql_shaped_catalog_rows(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( 'SHOW INDEX FROM `wptests_options`;' );

		$this->assertCount( 3, $indexes );
		$this->assertSame( 'SHOW INDEX FROM `wptests_options`;', $driver->get_last_mysql_query() );
		$this->assertSame( 15, $driver->get_last_column_count() );
		$this->assertSame( 'Table', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'Key_name', $driver->get_last_column_meta()[2]['name'] );

		$this->assertCount( 15, get_object_vars( $indexes[0] ) );
		$this->assertSame( 'wptests_options', $indexes[0]->Table );
		$this->assertSame( '0', $indexes[0]->Non_unique );
		$this->assertSame( 'PRIMARY', $indexes[0]->Key_name );
		$this->assertSame( '1', $indexes[0]->Seq_in_index );
		$this->assertSame( 'option_id', $indexes[0]->Column_name );
		$this->assertSame( 'A', $indexes[0]->Collation );
		$this->assertSame( '0', $indexes[0]->Cardinality );
		$this->assertNull( $indexes[0]->Sub_part );
		$this->assertNull( $indexes[0]->Packed );
		$this->assertSame( '', $indexes[0]->Null );
		$this->assertSame( 'BTREE', $indexes[0]->Index_type );
		$this->assertSame( '', $indexes[0]->Comment );
		$this->assertSame( '', $indexes[0]->Index_comment );
		$this->assertSame( 'YES', $indexes[0]->Visible );
		$this->assertNull( $indexes[0]->Expression );

		$this->assertSame( 'option_name', $indexes[1]->Key_name );
		$this->assertSame( 'option_name', $indexes[1]->Column_name );
		$this->assertSame( '0', $indexes[1]->Non_unique );
		$this->assertSame( 'autoload', $indexes[2]->Key_name );
		$this->assertSame( 'autoload', $indexes[2]->Column_name );
		$this->assertSame( '1', $indexes[2]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'pg_catalog.pg_index', $queries[0]['sql'] );
		$this->assertStringContainsString( 'pg_catalog.unnest(i.indkey)', $queries[0]['sql'] );
		$this->assertStringContainsString( 'show_index_rows', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW INDEX', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW INDEXES WHERE Key_name filters on normalized MySQL index names.
	 */
	public function test_show_indexes_where_key_name_filters_catalog_rows(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( "SHOW INDEXES FROM wptests_options WHERE Key_name = 'autoload'" );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'autoload', $indexes[0]->Key_name );
		$this->assertSame( 'autoload', $indexes[0]->Column_name );
		$this->assertSame( '1', $indexes[0]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Key_name" = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW INDEXES', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'autoload' ), $queries[0]['params'] );
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
	 * Tests the emulated MySQL session SQL mode can be selected.
	 */
	public function test_select_session_sql_mode_returns_emulated_driver_state(): void {
		$driver = $this->create_driver();

		$driver->set_sql_mode( 'IGNORE_SPACE,NO_AUTO_VALUE_ON_ZERO' );

		$rows = $driver->query( 'SELECT @@SESSION.sql_mode;' );

		$this->assertSame( 'IGNORE_SPACE,NO_AUTO_VALUE_ON_ZERO', $rows[0]->{'@@SESSION.sql_mode'} );
		$this->assertSame( 'SELECT @@SESSION.sql_mode;', $driver->get_last_mysql_query() );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( '@@SESSION.sql_mode', $driver->get_last_column_meta()[0]['name'] );
	}

	/**
	 * Tests SET NAMES updates MySQL-compatible SHOW VARIABLES output.
	 */
	public function test_set_names_updates_show_variables_session_state(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( "SET NAMES 'utf8' COLLATE 'utf8_general_ci'" ) );

		$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
		$this->assertCount( 1, $collation );
		$this->assertSame( 'collation_connection', $collation[0]->Variable_name );
		$this->assertSame( 'utf8_general_ci', $collation[0]->Value );

		$charset = $driver->query( "SHOW VARIABLES LIKE 'character_set_client'" );
		$this->assertCount( 1, $charset );
		$this->assertSame( 'utf8', $charset[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests SET NAMES DEFAULT resets to the emulated MySQL defaults.
	 */
	public function test_set_names_default_resets_show_variables_session_state(): void {
		$driver = $this->create_driver();

		$driver->query( "SET NAMES 'utf8' COLLATE 'utf8_general_ci'" );
		$this->assertSame( 0, $driver->query( 'SET NAMES DEFAULT' ) );

		$charset = $driver->query( "SHOW VARIABLES WHERE Variable_name='character_set_client'" );
		$this->assertCount( 1, $charset );
		$this->assertSame( 'utf8mb4', $charset[0]->Value );

		$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
		$this->assertCount( 1, $collation );
		$this->assertSame( 'utf8mb4_unicode_ci', $collation[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests SHOW VARIABLES LIKE honors MySQL wildcard patterns.
	 */
	public function test_show_variables_like_matches_wildcard_patterns(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SHOW VARIABLES LIKE 'character_set_%'" );

		$this->assertSame(
			array(
				'character_set_client',
				'character_set_connection',
				'character_set_results',
				'character_set_database',
				'character_set_server',
			),
			array_map(
				static function ( $row ) {
					return $row->Variable_name;
				},
				$rows
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
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
	 * Install a PostgreSQL-like options table and matching MySQL column metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_options_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_options (
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL,
				autoload TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);
	}

	/**
	 * Creates a PostgreSQL driver backed by an injected in-memory PDO.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver( string $db_name = 'wptests' ): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		return new WP_PostgreSQL_Driver( $connection, $db_name );
	}

	/**
	 * Get a DML identity metadata fixture row.
	 *
	 * @param string $table_name    Table name.
	 * @param string $column_name   Identity column name.
	 * @param string $sequence_name Sequence name.
	 * @return array[] Fixture metadata rows.
	 */
	private function get_dml_identity_metadata_fixture( string $table_name, string $column_name, string $sequence_name ): array {
		return array(
			array(
				'table_schema'      => 'public',
				'table_name'        => $table_name,
				'column_name'       => $column_name,
				'ordinal_position'  => 1,
				'data_type'         => 'bigint',
				'is_identity'       => 'YES',
				'column_default'    => null,
				'mysql_column_type' => 'bigint(20)',
				'mysql_extra'       => 'auto_increment',
				'sequence_schema'   => 'public',
				'sequence_name'     => $sequence_name,
			),
		);
	}

	/**
	 * Assert that a logged query is a guarded identity sequence repair query.
	 *
	 * @param array  $query         Logged query.
	 * @param string $table_name    Table name.
	 * @param string $column_name   Identity column name.
	 * @param string $sequence_name Sequence name.
	 */
	private function assert_sequence_repair_query( array $query, string $table_name, string $column_name, string $sequence_name ): void {
		$sequence_identifier = '"public"."' . $sequence_name . '"';

		$this->assertSame( array( $sequence_identifier ), $query['params'] );
		$this->assertStringContainsString( 'SELECT last_value, is_called FROM ' . $sequence_identifier, $query['sql'] );
		$this->assertStringContainsString( 'MAX("' . $column_name . '") AS max_identity_value FROM "public"."' . $table_name . '"', $query['sql'] );
		$this->assertStringContainsString( 'SELECT pg_catalog.setval(CAST(? AS regclass), table_state.max_identity_value, true)', $query['sql'] );
		$this->assertStringContainsString( 'table_state.max_identity_value > sequence_state.last_value', $query['sql'] );
		$this->assertStringContainsString( 'NOT sequence_state.is_called', $query['sql'] );
	}

	/**
	 * Creates a PostgreSQL driver with a SQLite shim for SUBSTRING(text, pattern).
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver_with_postgresql_substring_function(): WP_PostgreSQL_Driver {
		$pdo_class = class_exists( 'Pdo\Sqlite' ) ? 'Pdo\Sqlite' : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );
		$substring = static function ( $value, $pattern ): ?string {
			if ( null === $value ) {
				return null;
			}

			$php_pattern = '/' . str_replace( '/', '\\/', (string) $pattern ) . '/';
			if ( 1 === preg_match( $php_pattern, (string) $value, $matches ) ) {
				return $matches[0];
			}

			return null;
		};

		if ( method_exists( $pdo, 'createFunction' ) ) {
			$pdo->createFunction( 'SUBSTRING', $substring, 2 );
		} else {
			$pdo->sqliteCreateFunction( 'SUBSTRING', $substring, 2 );
		}

		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Translate a query by calling a private driver translator.
	 *
	 * @param WP_PostgreSQL_Driver $driver      Driver under test.
	 * @param string               $method_name Private driver method name.
	 * @param string               $query       MySQL query.
	 * @return string|null PostgreSQL SQL, or null when unsupported.
	 */
	private function translate_driver_query_with_private_method( WP_PostgreSQL_Driver $driver, string $method_name, string $query ): ?string {
		$translator = Closure::bind(
			function ( string $bound_method_name, string $bound_query ): ?string {
				return $this->$bound_method_name( $bound_query );
			},
			$driver,
			WP_PostgreSQL_Driver::class
		);

		return $translator( $method_name, $query );
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL-compatible integer casts.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_integer_cast_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL ELSE CAST(COALESCE(SUBSTRING(%1$s, \'^[[:space:]]*[+-]?[0-9]+\'), \'0\') AS bigint) END',
			$expression_text_sql
		);
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL-compatible decimal text coercion.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_numeric_cast_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$substring_sql       = array();
		$numeric_patterns    = array(
			'^[[:space:]]*[+-]?[0-9]+[.][0-9]*[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[.][0-9]+[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[0-9]+[eE][+-]?[0-9]+',
			'^[[:space:]]*[+-]?[0-9]+[.][0-9]*',
			'^[[:space:]]*[+-]?[.][0-9]+',
			'^[[:space:]]*[+-]?[0-9]+',
		);

		foreach ( $numeric_patterns as $pattern ) {
			$substring_sql[] = sprintf(
				'SUBSTRING(%1$s, \'%2$s\')',
				$expression_text_sql,
				$pattern
			);
		}

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL ELSE CAST(COALESCE(%2$s, \'0\') AS numeric) END',
			$expression_text_sql,
			implode( ', ', $substring_sql )
		);
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL DATE_ADD/DATE_SUB arithmetic.
	 *
	 * @param string $operator       PostgreSQL interval operator.
	 * @param string $expression_sql PostgreSQL date/time expression SQL.
	 * @param string $value_sql      PostgreSQL interval value SQL.
	 * @param string $unit           PostgreSQL interval unit.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_date_arithmetic_sql( string $operator, string $expression_sql, string $value_sql, string $unit ): string {
		return sprintf(
			'(%1$s %2$s (%3$s * INTERVAL \'1 %4$s\'))',
			$this->get_expected_zero_date_safe_timestamp_sql( $expression_sql ),
			$operator,
			$this->get_expected_mysql_interval_value_sql( $value_sql ),
			$unit
		);
	}

	/**
	 * Get expected PostgreSQL SQL for a MySQL-compatible interval value.
	 *
	 * @param string $value_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_interval_value_sql( string $value_sql ): string {
		return sprintf( 'CAST(%s AS double precision)', $this->get_expected_mysql_integer_cast_sql( $value_sql ) );
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL WEEK(expr, 1).
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_week_mode_one_sql( string $expression_sql ): string {
		$timestamp_sql        = $this->get_expected_zero_date_safe_timestamp_sql( $expression_sql );
		$week_start_sql       = sprintf( "DATE_TRUNC('week', %s)", $timestamp_sql );
		$year_start_sql       = sprintf( "DATE_TRUNC('year', %s)", $timestamp_sql );
		$first_week_start_sql = sprintf(
			"(CASE WHEN EXTRACT(ISODOW FROM %1\$s) <= 4 THEN DATE_TRUNC('week', %1\$s) ELSE DATE_TRUNC('week', %1\$s) + INTERVAL '1 week' END)",
			$year_start_sql
		);

		return sprintf(
			'CASE WHEN %1$s IS NULL THEN NULL WHEN %2$s < %3$s THEN 0 ELSE CAST(FLOOR(EXTRACT(EPOCH FROM (%2$s - %3$s)) / 604800) AS integer) + 1 END',
			$timestamp_sql,
			$week_start_sql,
			$first_week_start_sql
		);
	}

	/**
	 * Get expected PostgreSQL SQL for a MySQL weekday index function.
	 *
	 * @param string $function_name  Lowercase MySQL function name.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_weekday_index_sql( string $function_name, string $expression_sql ): string {
		$timestamp_sql = $this->get_expected_zero_date_safe_timestamp_sql( $expression_sql );

		if ( 'dayofweek' === $function_name ) {
			return sprintf( 'CAST(EXTRACT(DOW FROM %s) AS integer) + 1', $timestamp_sql );
		}

		return sprintf( 'CAST(EXTRACT(ISODOW FROM %s) AS integer) - 1', $timestamp_sql );
	}

	/**
	 * Get expected PostgreSQL SQL for a supported MySQL DATE_FORMAT() format.
	 *
	 * @param string $format         MySQL DATE_FORMAT format.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_date_format_sql( string $format, string $expression_sql ): string {
		if ( '%H.%i' === $format ) {
			return $this->get_expected_mysql_date_format_hour_minute_sql( $expression_sql );
		}

		if ( '%Y-%m-%d' === $format ) {
			return $this->get_expected_mysql_date_format_year_month_day_sql( $expression_sql );
		}

		throw new InvalidArgumentException( 'Unsupported test date format.' );
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL DATE_FORMAT(expr, '%H.%i').
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_date_format_hour_minute_sql( string $expression_sql ): string {
		$expression_text_sql  = sprintf( 'CAST(%s AS text)', $expression_sql );
		$zero_date_format_sql = sprintf(
			'CASE WHEN %1$s ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}\' THEN CAST(SUBSTRING(%1$s FROM 12 FOR 2) || \'.\' || SUBSTRING(%1$s FROM 15 FOR 2) AS double precision) ELSE 0 END',
			$expression_text_sql
		);

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(TO_CHAR(%3$s, \'HH24.MI\') AS double precision) END',
			$this->get_expected_zero_date_condition_sql( $expression_text_sql ),
			$zero_date_format_sql,
			$this->get_expected_zero_date_safe_timestamp_sql( $expression_sql )
		);
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL DATE_FORMAT(expr, '%Y-%m-%d').
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_date_format_year_month_day_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

		return sprintf(
			'CASE WHEN %1$s THEN SUBSTRING(%2$s FROM 1 FOR 10) ELSE TO_CHAR(%3$s, \'YYYY-MM-DD\') END',
			$this->get_expected_zero_date_condition_sql( $expression_text_sql ),
			$expression_text_sql,
			$this->get_expected_zero_date_safe_timestamp_sql( $expression_sql )
		);
	}

	/**
	 * Get expected zero-date-safe PostgreSQL date/time extract SQL.
	 *
	 * @param string $unit           PostgreSQL EXTRACT unit.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_zero_date_safe_extract_sql( string $unit, string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$zero_date_condition = $this->get_expected_zero_date_condition_sql( $expression_text_sql );

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(EXTRACT(%3$s FROM %4$s) AS integer) END',
			$zero_date_condition,
			$this->get_expected_zero_date_extract_part_sql( $unit, $expression_text_sql ),
			$unit,
			$this->get_expected_zero_date_safe_timestamp_sql( $expression_sql )
		);
	}

	/**
	 * Get expected PostgreSQL SQL that casts a MySQL date/time without casting zero dates.
	 *
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_zero_date_safe_timestamp_sql( string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );

		return sprintf(
			'CAST(CASE WHEN %1$s THEN NULL ELSE %2$s END AS timestamp)',
			$this->get_expected_zero_date_condition_sql( $expression_text_sql ),
			$expression_text_sql
		);
	}

	/**
	 * Get expected condition that detects MySQL zero or partial-zero date strings.
	 *
	 * @param string $expression_text_sql PostgreSQL expression cast to text.
	 * @return string PostgreSQL condition SQL.
	 */
	private function get_expected_zero_date_condition_sql( string $expression_text_sql ): string {
		return sprintf(
			'%1$s ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}\' AND (SUBSTRING(%1$s FROM 1 FOR 4) = \'0000\' OR SUBSTRING(%1$s FROM 6 FOR 2) = \'00\' OR SUBSTRING(%1$s FROM 9 FOR 2) = \'00\')',
			$expression_text_sql
		);
	}

	/**
	 * Get expected PostgreSQL SQL that extracts one part from a zero-ish date string.
	 *
	 * @param string $unit                PostgreSQL EXTRACT unit.
	 * @param string $expression_text_sql PostgreSQL expression cast to text.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_zero_date_extract_part_sql( string $unit, string $expression_text_sql ): string {
		switch ( $unit ) {
			case 'YEAR':
				return sprintf( 'CAST(SUBSTRING(%s FROM 1 FOR 4) AS integer)', $expression_text_sql );

			case 'MONTH':
				return sprintf( 'CAST(SUBSTRING(%s FROM 6 FOR 2) AS integer)', $expression_text_sql );

			case 'DAY':
				return sprintf( 'CAST(SUBSTRING(%s FROM 9 FOR 2) AS integer)', $expression_text_sql );

			case 'HOUR':
				$start = 12;
				break;

			case 'MINUTE':
				$start = 15;
				break;

			case 'SECOND':
				$start = 18;
				break;

			default:
				throw new InvalidArgumentException( 'Unsupported test extract unit.' );
		}

		return sprintf(
			'CASE WHEN %1$s ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}\' THEN CAST(SUBSTRING(%1$s FROM %2$d FOR 2) AS integer) ELSE 0 END',
			$expression_text_sql,
			$start
		);
	}

	/**
	 * Check whether an injected SQLite backend table exists.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $schema     SQLite schema name.
	 * @param string               $table_name Table name.
	 * @return bool Whether the table exists.
	 */
	private function sqlite_table_exists( WP_PostgreSQL_Driver $driver, string $schema, string $table_name ): bool {
		if ( 'temp' === $schema ) {
			$catalog = 'sqlite_temp_master';
		} elseif ( 'main' === $schema ) {
			$catalog = 'sqlite_master';
		} else {
			throw new InvalidArgumentException( 'Unsupported SQLite schema for test table lookup.' );
		}

		$stmt = $driver->get_connection()->query(
			sprintf(
				"SELECT name FROM %s WHERE type = 'table' AND name = ?",
				$catalog
			),
			array( $table_name )
		);

		return false !== $stmt->fetchColumn();
	}

	/**
	 * Get stored MySQL column metadata rows for a table.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $table_name Table name.
	 * @param string               $schema     Metadata schema name.
	 * @return array Stored metadata rows.
	 */
	private function get_mysql_column_metadata_rows( WP_PostgreSQL_Driver $driver, string $table_name, string $schema = 'public' ): array {
		$stmt = $driver->get_connection()->query(
			sprintf(
				'SELECT column_name, column_type, character_set_name, collation_name, is_nullable, column_default, extra
				FROM %s
				WHERE table_schema = ? AND table_name = ?
				ORDER BY ordinal_position',
				$driver->get_connection()->quote_identifier( WP_PostgreSQL_Driver::MYSQL_COLUMN_METADATA_TABLE )
			),
			array( $schema, $table_name )
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Get stored MySQL index metadata rows for a table.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $table_name Table name.
	 * @param string               $schema     Metadata schema name.
	 * @return array Stored metadata rows.
	 */
	private function get_mysql_index_metadata_rows( WP_PostgreSQL_Driver $driver, string $table_name, string $schema = 'public' ): array {
		$stmt = $driver->get_connection()->query(
			sprintf(
				'SELECT key_name, seq_in_index, column_name, non_unique, index_type, sub_part, nullable
				FROM %s
				WHERE table_schema = ? AND table_name = ?
				ORDER BY index_ordinal, seq_in_index',
				$driver->get_connection()->quote_identifier( WP_PostgreSQL_Driver::MYSQL_INDEX_METADATA_TABLE )
			),
			array( $schema, $table_name )
		);

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Creates a PostgreSQL driver with SHOW INDEX fixture rows.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_show_index_driver(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Driver_Show_Index_Fixture_Connection();
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Install backend tables used by Site Health TABLE_ROWS emulation tests.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_site_health_table_count_fixture( WP_PostgreSQL_Driver $driver ): void {
		$pdo        = $driver->get_connection()->get_pdo();
		$connection = $driver->get_connection();
		$schema     = $connection->quote_identifier( 'public' );

		$pdo->exec( "ATTACH DATABASE ':memory:' AS public" );
		foreach ( array( 'wptests_options', 'wptests_posts' ) as $table_name ) {
			$pdo->exec(
				sprintf(
					'CREATE TABLE %s.%s (id INTEGER)',
					$schema,
					$connection->quote_identifier( $table_name )
				)
			);
		}

		$options_table = $schema . '.' . $connection->quote_identifier( 'wptests_options' );
		$posts_table   = $schema . '.' . $connection->quote_identifier( 'wptests_posts' );

		$pdo->exec( 'INSERT INTO ' . $options_table . ' (id) VALUES (1), (2)' );
		$pdo->exec( 'INSERT INTO ' . $posts_table . ' (id) VALUES (1)' );
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
				collation_name TEXT,
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
				(table_schema, table_name, column_name, ordinal_position, data_type, character_maximum_length, collation_name, is_nullable, column_default, is_identity)
			VALUES
				('public', 'wptests_options', 'option_id', 1, 'bigint', NULL, NULL, 'NO', NULL, 'YES'),
				('public', 'wptests_options', 'option_name', 2, 'character varying', 191, 'utf8mb4_unicode_ci', 'NO', NULL, 'NO'),
				('public', 'wptests_options', 'option_value', 3, 'text', NULL, 'utf8mb4_unicode_ci', 'NO', NULL, 'NO'),
				('public', 'wptests_options', 'autoload', 4, 'character varying', 20, 'utf8mb4_unicode_ci', 'NO', '''yes''::character varying', 'NO')"
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

/**
 * Fixture connection that accepts PostgreSQL ALTER TABLE syntax in driver tests.
 */
class WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Whether DML identity metadata rows are installed.
	 *
	 * @var bool
	 */
	private $has_identity_metadata_fixture = false;

	/**
	 * Number of sequence repair queries executed.
	 *
	 * @var int
	 */
	private $sequence_sync_query_count = 0;

	/**
	 * Constructor.
	 *
	 * @param array[] $identity_metadata_rows Optional fixture identity metadata rows.
	 */
	public function __construct( array $identity_metadata_rows = array() ) {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );

		if ( ! empty( $identity_metadata_rows ) ) {
			$this->install_information_schema_marker();
			$this->install_identity_metadata_fixture( $identity_metadata_rows );
			$this->has_identity_metadata_fixture = true;
		}
	}

	/**
	 * Execute a query against PostgreSQL test fixtures when needed.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Query parameters.
	 * @return PDOStatement Statement.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->has_identity_metadata_fixture && false !== strpos( $sql, 'pg_catalog.pg_get_serial_sequence' ) ) {
			return parent::query(
				'SELECT
					column_name,
					data_type,
					is_identity,
					column_default,
					mysql_column_type,
					mysql_extra,
					sequence_schema,
					sequence_name
				FROM dml_identity_metadata_fixture
				WHERE table_schema = ?
					AND table_name = ?
				ORDER BY ordinal_position',
				array( $params[0] ?? '', $params[1] ?? '' )
			);
		}

		if ( $this->has_identity_metadata_fixture && false !== strpos( $sql, 'pg_catalog.setval' ) ) {
			++$this->sequence_sync_query_count;
			return parent::query( 'SELECT 1' );
		}

		if ( 0 === strpos( $sql, 'ALTER TABLE ' ) ) {
			return parent::query( 'SELECT 1 WHERE 0 = 1' );
		}

		return parent::query( $sql, $params );
	}

	/**
	 * Get the number of sequence repair queries executed.
	 *
	 * @return int Sequence repair query count.
	 */
	public function get_sequence_sync_query_count(): int {
		return $this->sequence_sync_query_count;
	}

	/**
	 * Install the information_schema marker used by the SQLite test shim.
	 */
	private function install_information_schema_marker(): void {
		$pdo = $this->get_pdo();
		$pdo->exec( "ATTACH DATABASE ':memory:' AS information_schema" );
		$pdo->exec( 'CREATE TABLE information_schema.columns (table_schema TEXT)' );
	}

	/**
	 * Install identity metadata rows.
	 *
	 * @param array[] $identity_metadata_rows Fixture identity metadata rows.
	 */
	private function install_identity_metadata_fixture( array $identity_metadata_rows ): void {
		parent::query(
			'CREATE TABLE dml_identity_metadata_fixture (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				column_name TEXT NOT NULL,
				ordinal_position INTEGER NOT NULL,
				data_type TEXT NOT NULL,
				is_identity TEXT NOT NULL,
				column_default TEXT,
				mysql_column_type TEXT,
				mysql_extra TEXT NOT NULL,
				sequence_schema TEXT,
				sequence_name TEXT
			)'
		);

		foreach ( $identity_metadata_rows as $row ) {
			parent::query(
				'INSERT INTO dml_identity_metadata_fixture
					(table_schema, table_name, column_name, ordinal_position, data_type, is_identity, column_default, mysql_column_type, mysql_extra, sequence_schema, sequence_name)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				array(
					$row['table_schema'] ?? 'public',
					$row['table_name'],
					$row['column_name'],
					$row['ordinal_position'] ?? 1,
					$row['data_type'] ?? 'bigint',
					$row['is_identity'] ?? 'YES',
					$row['column_default'] ?? null,
					$row['mysql_column_type'] ?? 'bigint(20)',
					$row['mysql_extra'] ?? 'auto_increment',
					$row['sequence_schema'] ?? 'public',
					$row['sequence_name'],
				)
			);
		}
	}
}

/**
 * Fixture connection for PostgreSQL SHOW INDEX catalog tests.
 */
class WP_PostgreSQL_Driver_Show_Index_Fixture_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );

		$this->install_fixture();
	}

	/**
	 * Execute a query against the fixture when the PostgreSQL catalog query is used.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Query parameters.
	 * @return PDOStatement Statement.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false === strpos( $sql, 'pg_catalog.pg_index' ) ) {
			return parent::query( $sql, $params );
		}

		$fixture_sql    = 'SELECT
			table_name AS "Table",
			non_unique AS "Non_unique",
			key_name AS "Key_name",
			seq_in_index AS "Seq_in_index",
			column_name AS "Column_name",
			collation AS "Collation",
			cardinality AS "Cardinality",
			sub_part AS "Sub_part",
			packed AS "Packed",
			nullable AS "Null",
			index_type AS "Index_type",
			comment AS "Comment",
			index_comment AS "Index_comment",
			visible AS "Visible",
			expression AS "Expression"
		FROM show_index_fixture
		WHERE table_schema = ?
			AND table_name = ?';
		$fixture_params = array( $params[0] ?? '', $params[1] ?? '' );

		if ( isset( $params[2] ) ) {
			$fixture_sql     .= '
			AND key_name = ?';
			$fixture_params[] = $params[2];
		}

		$fixture_sql .= '
		ORDER BY sort_position, CAST(seq_in_index AS INTEGER)';

		return parent::query( $fixture_sql, $fixture_params );
	}

	/**
	 * Install SHOW INDEX fixture rows into the injected PDO.
	 */
	private function install_fixture(): void {
		$pdo = $this->get_pdo();

		$pdo->exec(
			'CREATE TABLE show_index_fixture (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				sort_position INTEGER NOT NULL,
				non_unique TEXT NOT NULL,
				key_name TEXT NOT NULL,
				seq_in_index TEXT NOT NULL,
				column_name TEXT,
				collation TEXT,
				cardinality TEXT,
				sub_part TEXT,
				packed TEXT,
				nullable TEXT NOT NULL,
				index_type TEXT NOT NULL,
				comment TEXT NOT NULL,
				index_comment TEXT NOT NULL,
				visible TEXT NOT NULL,
				expression TEXT
			)'
		);
		$pdo->exec(
			"INSERT INTO show_index_fixture
				(table_schema, table_name, sort_position, non_unique, key_name, seq_in_index, column_name, collation, cardinality, sub_part, packed, nullable, index_type, comment, index_comment, visible, expression)
			VALUES
				('public', 'wptests_options', 1, '0', 'PRIMARY', '1', 'option_id', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_options', 2, '0', 'option_name', '1', 'option_name', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_options', 3, '1', 'autoload', '1', 'autoload', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_posts', 4, '0', 'PRIMARY', '1', 'ID', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL)"
		);
	}
}
