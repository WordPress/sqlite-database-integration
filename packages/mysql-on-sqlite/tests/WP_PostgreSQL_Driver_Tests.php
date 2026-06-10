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
	 * Tests SELECT DISTINCT term ID queries include ORDER BY expressions.
	 */
	public function test_distinct_term_id_order_by_name_includes_order_expression_for_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Beta')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Alpha')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (10, 1, 'category')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (20, 2, 'category')" );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (1, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (1, 20)' );

		$select = "SELECT DISTINCT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('category') AND tr.object_id IN (1)
			ORDER BY t.name ASC";
		$rows   = $driver->query( $select );

		$this->assertSame( '2', $rows[0]->term_id );
		$this->assertSame( '1', $rows[1]->term_id );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT DISTINCT t.term_id, t.name FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN (\'category\') AND tr.object_id IN (1) ORDER BY t.name ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
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
	 * Creates a PostgreSQL driver backed by an injected in-memory PDO.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
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
	 * Get expected zero-date-safe PostgreSQL date/time extract SQL.
	 *
	 * @param string $unit           PostgreSQL EXTRACT unit.
	 * @param string $expression_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_zero_date_safe_extract_sql( string $unit, string $expression_sql ): string {
		$expression_text_sql = sprintf( 'CAST(%s AS text)', $expression_sql );
		$zero_date_condition = sprintf(
			'%1$s ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}\' AND (SUBSTRING(%1$s FROM 1 FOR 4) = \'0000\' OR SUBSTRING(%1$s FROM 6 FOR 2) = \'00\' OR SUBSTRING(%1$s FROM 9 FOR 2) = \'00\')',
			$expression_text_sql
		);

		return sprintf(
			'CASE WHEN %1$s THEN %2$s ELSE CAST(EXTRACT(%3$s FROM CAST(%4$s AS timestamp)) AS integer) END',
			$zero_date_condition,
			$this->get_expected_zero_date_extract_part_sql( $unit, $expression_text_sql ),
			$unit,
			$expression_sql
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
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
	}

	/**
	 * Execute a query, accepting PostgreSQL ALTER TABLE statements as no-ops.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Query parameters.
	 * @return PDOStatement Statement.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( 0 === strpos( $sql, 'ALTER TABLE ' ) ) {
			return parent::query( 'SELECT 1 WHERE 0 = 1' );
		}

		return parent::query( $sql, $params );
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
