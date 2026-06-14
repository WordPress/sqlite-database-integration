<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection.php';
require_once __DIR__ . '/WP_PostgreSQL_Driver_Show_Index_Fixture_Connection.php';
require_once __DIR__ . '/WP_PostgreSQL_Connection_Pgsql_Quote_SQLite_Connection.php';
require_once __DIR__ . '/WP_PostgreSQL_Connection_Stale_Insert_ID_SQLite_Connection.php';

/**
 * Unit tests for the PostgreSQL driver scaffold.
 */
class WP_PostgreSQL_Driver_Tests extends TestCase {
	/**
	 * Number of times the static FETCH_FUNC regression callback was invoked.
	 *
	 * @var int
	 */
	private static $mysql_introspection_fetch_func_invocations = 0;

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
	 * Tests MySQL optimizer index hints are removed before PostgreSQL execution.
	 */
	public function test_select_index_hints_are_removed_before_postgresql_execution(): void {
		$driver = $this->create_driver_with_index_hint_tables();

		$queries = array(
			'SELECT * FROM t USE INDEX (i)'    => 'SELECT * FROM t',
			'SELECT * FROM t USE KEY (k)'      => 'SELECT * FROM t',
			'SELECT * FROM t FORCE INDEX (i)'  => 'SELECT * FROM t',
			'SELECT * FROM t FORCE KEY (k)'    => 'SELECT * FROM t',
			'SELECT * FROM t IGNORE INDEX (i)' => 'SELECT * FROM t',
			'SELECT * FROM t IGNORE KEY (k)'   => 'SELECT * FROM t',
			'SELECT * FROM t USE INDEX FOR JOIN (i) JOIN j ON t.id = j.t_id' => 'SELECT * FROM t JOIN j ON t.id = j.t_id',
			'SELECT * FROM t USE INDEX FOR ORDER BY (i) ORDER BY id DESC' => 'SELECT * FROM t ORDER BY id DESC',
			'SELECT * FROM t USE INDEX FOR GROUP BY (i) GROUP BY id HAVING id = 1' => 'SELECT * FROM t GROUP BY id HAVING id = 1',
			'SELECT * FROM `t` USE INDEX (i) USE INDEX FOR JOIN (j) USE KEY FOR ORDER BY (o) IGNORE INDEX FOR GROUP BY (g) JOIN j ON t.id = j.t_id WHERE id = 1 GROUP BY id HAVING id = 1 ORDER BY id DESC' => 'SELECT * FROM "t" JOIN j ON t.id = j.t_id WHERE id = 1 GROUP BY id HAVING id = 1 ORDER BY id DESC',
		);

		foreach ( $queries as $mysql_query => $postgresql_sql ) {
			$rows = $driver->query( $mysql_query );

			$this->assertSame( array(), $rows, $mysql_query );
			$sql = $this->get_last_single_postgresql_sql( $driver );
			$this->assertSame( $postgresql_sql, $sql, $mysql_query );
			$this->assert_postgresql_sql_omits_mysql_index_hints( $sql );
		}
	}

	/**
	 * Tests quoted table and index identifiers keep aliases while index hints are removed.
	 */
	public function test_select_index_hints_preserve_quoted_table_and_alias(): void {
		$driver = $this->create_driver_with_index_hint_tables();

		$driver->query( "INSERT INTO t (id, value) VALUES (1, 'first')" );

		$rows = $driver->query( 'SELECT tt.id FROM `t` AS tt USE INDEX (`ix_t_id`) WHERE tt.id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertSame( 'SELECT tt.id FROM "t" AS tt WHERE tt.id = 1', $sql );
		$this->assert_postgresql_sql_omits_mysql_index_hints( $sql );
	}

	/**
	 * Tests malformed MySQL index hints are not sent raw to PostgreSQL.
	 */
	public function test_malformed_select_index_hint_is_rejected_before_postgresql_execution(): void {
		$driver = $this->create_driver_with_index_hint_tables();

		try {
			$driver->query( 'SELECT * FROM t USE INDEX' );
			$this->fail( 'Malformed MySQL index hint was not rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( 'Unsupported MySQL index hint syntax.', $exception->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests result column metadata is normalized only when requested.
	 */
	public function test_query_defers_column_metadata_until_requested(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT 1 AS id, 'ok' AS value" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $driver->get_last_column_count() );
		$this->assertSame( array(), $this->get_driver_private_property( $driver, 'last_column_meta' ) );
		$this->assertInstanceOf( PDOStatement::class, $this->get_driver_private_property( $driver, 'last_column_meta_statement' ) );

		$column_meta = $driver->get_last_column_meta();
		$this->assertCount( 2, $column_meta );
		$this->assertSame( 'id', $column_meta[0]['name'] );
		$this->assertNull( $this->get_driver_private_property( $driver, 'last_column_meta_statement' ) );
	}

	/**
	 * Tests fetched PostgreSQL-safe text decodes to MySQL NUL bytes.
	 */
	public function test_query_decodes_postgresql_text_sentinel_to_mysql_nul_byte(): void {
		$driver     = $this->create_driver_with_postgresql_quote_translation();
		$connection = $driver->get_connection();

		$driver->query( 'CREATE TABLE t (value TEXT NOT NULL)' );
		$connection->query( 'INSERT INTO t (value) VALUES (' . $connection->quote( "protected\0property" ) . ')' );

		$stored_rows = $connection->query( 'SELECT value FROM t' )->fetchAll( PDO::FETCH_OBJ );
		$this->assertCount( 1, $stored_rows );
		$this->assertStringNotContainsString( "\0", $stored_rows[0]->value );
		$this->assertStringContainsString( 'WP_MYSQL_TEXT_V1:', $stored_rows[0]->value );

		$rows = $driver->query( 'SELECT value FROM t' );

		$this->assertCount( 1, $rows );
		$this->assertSame( "protected\0property", $rows[0]->value );
	}

	/**
	 * Tests external sentinel-shaped PostgreSQL text is preserved on fetch.
	 */
	public function test_query_preserves_external_postgresql_text_sentinel_collision_shape(): void {
		$driver     = $this->create_driver_with_postgresql_quote_translation();
		$connection = $driver->get_connection();

		$driver->query( 'CREATE TABLE t (value TEXT NOT NULL)' );

		$external_value = 'pre' . "\xEE\x80\x80" . '0post';
		$connection->query( 'INSERT INTO t (value) VALUES (?)', array( $external_value ) );

		$rows = $driver->query( 'SELECT value FROM t' );

		$this->assertCount( 1, $rows );
		$this->assertSame( $external_value, $rows[0]->value );
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
	 * Tests DML column metadata is cached and invalidated after metadata changes.
	 */
	public function test_dml_column_metadata_cache_reuses_rows_until_metadata_changes(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query(
			'CREATE TABLE wptests_cache_dml (
				id INTEGER PRIMARY KEY,
				label TEXT NOT NULL,
				status TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_cache_dml (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(20) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'draft',
				PRIMARY KEY (id)
			)"
		);

		$metadata_select_count = 0;
		$connection->set_query_logger(
			static function ( string $sql, array $params ) use ( &$metadata_select_count ): void {
				if (
					false !== strpos( $sql, 'SELECT column_name, ordinal_position, column_type, is_nullable, column_default, extra' )
					&& false !== strpos( $sql, WP_PostgreSQL_Driver::MYSQL_COLUMN_METADATA_TABLE )
				) {
					++$metadata_select_count;
				}
			}
		);

		$this->assertSame( 1, $driver->query( 'INSERT INTO `wptests_cache_dml` (`id`) VALUES (1)' ) );
		$this->assertSame( 1, $driver->query( 'INSERT INTO `wptests_cache_dml` (`id`) VALUES (2)' ) );
		$this->assertSame( 1, $metadata_select_count );

		$driver->query( "ALTER TABLE wptests_cache_dml ALTER COLUMN status SET DEFAULT 'published'" );
		$this->assertSame( 1, $driver->query( 'INSERT INTO `wptests_cache_dml` (`id`) VALUES (3)' ) );
		$this->assertSame( 2, $metadata_select_count );

		$rows = $driver->query( 'SELECT id, label, status FROM wptests_cache_dml ORDER BY id' );
		$this->assertSame(
			array(
				array( '1', '', 'draft' ),
				array( '2', '', 'draft' ),
				array( '3', '', 'published' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->label, $row->status );
				},
				$rows
			)
		);
	}

	/**
	 * Tests DML identity metadata is cached and invalidated after metadata changes.
	 */
	public function test_dml_identity_metadata_cache_reuses_rows_until_metadata_changes(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_cache_identity', 'id', 'wptests_cache_identity_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_cache_identity (id INTEGER PRIMARY KEY, label TEXT NOT NULL)' );

		$metadata_select_count = 0;
		$connection->set_query_logger(
			static function ( string $sql, array $params ) use ( &$metadata_select_count ): void {
				if ( false !== strpos( $sql, 'FROM dml_identity_metadata_fixture' ) ) {
					++$metadata_select_count;
				}
			}
		);

		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_cache_identity` (`id`, `label`) VALUES (1, 'first')" ) );
		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_cache_identity` (`id`, `label`) VALUES (2, 'second')" ) );
		$this->assertSame( 1, $metadata_select_count );

		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_cache_identity (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(20) NOT NULL DEFAULT '',
				PRIMARY KEY (id)
			)"
		);
		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_cache_identity` (`id`, `label`) VALUES (3, 'third')" ) );
		$this->assertSame( 2, $metadata_select_count );
	}

	/**
	 * Tests MySQL tokenization reuses the most recent query token stream.
	 */
	public function test_mysql_token_cache_reuses_most_recent_query_tokens(): void {
		$driver     = $this->create_driver();
		$get_tokens = Closure::bind(
			function ( string $query ): array {
				return $this->get_mysql_tokens( $query );
			},
			$driver,
			WP_PostgreSQL_Driver::class
		);

		$first_tokens  = $get_tokens( 'SELECT ID FROM wptests_posts WHERE ID = 1' );
		$second_tokens = $get_tokens( 'SELECT ID FROM wptests_posts WHERE ID = 1' );
		$third_tokens  = $get_tokens( 'SELECT ID FROM wptests_posts WHERE ID = 2' );

		$this->assertSame( $first_tokens, $second_tokens );
		$this->assertNotSame( $first_tokens, $third_tokens );
	}

	/**
	 * Tests non-strict INSERT normalizes invalid date/time literals using MySQL metadata.
	 */
	public function test_non_strict_insert_normalizes_invalid_date_time_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$insert = "INSERT INTO `wptests_posts` (`ID`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`) VALUES (1, '2020-12-41 14:15:27', '0000-00-00 00:00:00', '2020-00-15 14:15:27', '2020-06-01T12:13:14Z')";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_posts" ("ID", "post_date", "post_date_gmt", "post_modified", "post_modified_gmt") VALUES (1, \'0000-00-00 00:00:00\', \'0000-00-00 00:00:00\', \'2020-00-15 14:15:27\', \'2020-06-01 12:13:14\')',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$posts = $driver->query( 'SELECT post_date, post_date_gmt, post_modified, post_modified_gmt FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $posts );
		$this->assertSame( '0000-00-00 00:00:00', $posts[0]->post_date );
		$this->assertSame( '0000-00-00 00:00:00', $posts[0]->post_date_gmt );
		$this->assertSame( '2020-00-15 14:15:27', $posts[0]->post_modified );
		$this->assertSame( '2020-06-01 12:13:14', $posts[0]->post_modified_gmt );
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
	 * Tests explicit identity upsert insert paths repair PostgreSQL sequences.
	 */
	public function test_explicit_identity_upsert_insert_repairs_sequence_after_success(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (7, 'identity')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") VALUES (7, \'identity\') ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$queries[0]['sql']
		);
		$this->assert_sequence_repair_query( $queries[1], 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests explicit identity upsert conflict paths do not repair sequences.
	 */
	public function test_explicit_identity_upsert_conflict_update_does_not_repair_sequence(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$this->install_identity_upsert_table_with_mysql_metadata( $driver );
		$driver->get_connection()->query( "INSERT INTO wptests_identity_upsert (id, value) VALUES (7, 'existing')" );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (7, 'updated')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") VALUES (7, \'updated\') ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$queries[0]['sql']
		);
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT value FROM wptests_identity_upsert WHERE id = 7' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'updated', $rows[0]->value );
	}

	/**
	 * Tests probe-unsafe identity upsert expressions fail closed.
	 */
	public function test_probe_unsafe_identity_upsert_expression_returns_null(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$this->install_identity_upsert_table_with_mysql_metadata( $driver );
		$driver->get_connection()->query( "INSERT INTO wptests_identity_upsert (id, value) VALUES (2, 'existing')" );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (next_identity_value(), 'updated')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$upsert
			)
		);

		try {
			$driver->query( $upsert );
			$this->fail( 'Probe-unsafe upsert expression should fail closed before sequence repair.' );
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
	 * Tests WooCommerce customer lookup REPLACE statements use customer_id conflicts.
	 */
	public function test_simple_wordpress_replace_with_customer_id_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_wc_customer_lookup (
				customer_id INTEGER PRIMARY KEY,
				user_id INTEGER NOT NULL,
				email TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_wc_customer_lookup (customer_id, user_id, email) VALUES (1, 1, 'old@example.com')" );

		$replace = "REPLACE INTO `wptests_wc_customer_lookup` (`user_id`, `email`, `customer_id`) VALUES (2, 'new@example.com', '1')";

		$this->assertSame( 2, $driver->query( $replace ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_wc_customer_lookup" ("user_id", "email", "customer_id") VALUES (2, \'new@example.com\', \'1\') ON CONFLICT ("customer_id") DO UPDATE SET "user_id" = excluded."user_id", "email" = excluded."email", "customer_id" = excluded."customer_id"',
			$queries[0]['sql']
		);

		$rows = $driver->query( 'SELECT user_id, email FROM wptests_wc_customer_lookup WHERE customer_id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->user_id );
		$this->assertSame( 'new@example.com', $rows[0]->email );
	}

	/**
	 * Tests WooCommerce product lookup REPLACE statements coerce integer values.
	 */
	public function test_woocommerce_product_lookup_replace_uses_product_id_conflict_and_integer_coercion(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_wc_product_meta_lookup (
				`product_id` bigint(20) unsigned NOT NULL,
				`sku` varchar(100) NOT NULL DEFAULT "",
				`total_sales` bigint(20) NOT NULL DEFAULT 0,
				PRIMARY KEY (`product_id`)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_wc_product_meta_lookup (`product_id`, `sku`, `total_sales`) VALUES (12, 'old-sku', 1)"
		);

		$replace = "REPLACE INTO `wptests_wc_product_meta_lookup` (`product_id`, `sku`, `total_sales`) VALUES ('12', 'DUMMY SKU100000', '4.000000')";

		$this->assertSame( 2, $driver->query( $replace ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'ON CONFLICT ("product_id") DO UPDATE SET', $queries[0]['sql'] );
		$this->assertStringContainsString( $this->get_expected_mysql_integer_cast_sql( "'4.000000'" ), $queries[0]['sql'] );

		$rows = $driver->query( 'SELECT sku, total_sales FROM wptests_wc_product_meta_lookup WHERE product_id = 12' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'DUMMY SKU100000', $rows[0]->sku );
		$this->assertSame( '4', $rows[0]->total_sales );
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
	 * Tests approved comments ordered by GMT date use comment_ID as a tie-breaker.
	 */
	public function test_simple_select_approved_comments_order_uses_comment_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, "comment_post_ID" INTEGER NOT NULL, comment_date_gmt TEXT NOT NULL, comment_approved TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (184, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (180, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (181, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (183, 8, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (185, 7, \'2024-01-01 00:00:00\', \'0\')' );

		$select = "SELECT *
			FROM wptests_comments
			WHERE comment_post_ID = 7 AND comment_approved = '1'
			ORDER BY wptests_comments.comment_date_gmt ASC";
		$rows   = $driver->query( $select );

		$this->assertSame(
			array( '180', '181', '184' ),
			array_map(
				static function ( $row ) {
					return $row->comment_ID;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT * FROM wptests_comments WHERE "comment_post_ID" = 7 AND comment_approved = \'1\' ORDER BY wptests_comments.comment_date_gmt ASC, "comment_ID" ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests approved comment ID lookups ordered by GMT date use comment_ID as a tie-breaker.
	 */
	public function test_simple_select_approved_comment_ids_order_uses_comment_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_comments ("comment_ID" INTEGER PRIMARY KEY, "comment_post_ID" INTEGER NOT NULL, comment_date_gmt TEXT NOT NULL, comment_approved TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (184, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (180, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (181, 7, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (183, 8, \'2024-01-01 00:00:00\', \'1\')' );
		$driver->query( 'INSERT INTO wptests_comments ("comment_ID", "comment_post_ID", comment_date_gmt, comment_approved) VALUES (185, 7, \'2024-01-01 00:00:00\', \'0\')' );

		$select = "SELECT wptests_comments.comment_ID
			FROM wptests_comments
			WHERE comment_post_ID = 7 AND comment_approved = '1'
			ORDER BY wptests_comments.comment_date_gmt ASC";
		$rows   = $driver->query( $select );

		$this->assertSame(
			array( '180', '181', '184' ),
			array_map(
				static function ( $row ) {
					return $row->comment_ID;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_comments."comment_ID" FROM wptests_comments WHERE "comment_post_ID" = 7 AND comment_approved = \'1\' ORDER BY wptests_comments.comment_date_gmt ASC, "comment_ID" ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests approved comment aggregate lookups are not rewritten with a row tie-breaker.
	 */
	public function test_simple_select_approved_comments_order_does_not_rewrite_count_projection(): void {
		$driver = $this->create_driver();

		$select = "SELECT COUNT(comment_ID) as c
			FROM wptests_comments
			WHERE comment_post_ID = 7 AND comment_approved = '1'
			ORDER BY wptests_comments.comment_date_gmt ASC";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_wordpress_approved_comments_query',
				$select
			)
		);
	}

	/**
	 * Tests approved comment projections must belong to the selected comments table.
	 */
	public function test_simple_select_approved_comments_order_does_not_rewrite_foreign_projection_qualifier(): void {
		$driver = $this->create_driver();

		$select = "SELECT other.comment_ID
			FROM wptests_comments
			WHERE comment_post_ID = 7 AND comment_approved = '1'
			ORDER BY wptests_comments.comment_date_gmt ASC";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_wordpress_approved_comments_query',
				$select
			)
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
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				value longtext NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO t (`value`) VALUES ('first')" );

		$this->assertSame( 1, $driver->get_insert_id() );
	}

	/**
	 * Tests INSERT ... SELECT statements expose generated AUTO_INCREMENT insert IDs.
	 */
	public function test_insert_select_from_dual_sets_generated_insert_id(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_actionscheduler_actions (
				action_id INTEGER PRIMARY KEY AUTOINCREMENT,
				hook TEXT NOT NULL,
				status TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_actionscheduler_actions (
				action_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hook varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				PRIMARY KEY (action_id)
			)'
		);

		$insert = "INSERT INTO wptests_actionscheduler_actions (`hook`, `status`)
			SELECT 'action_scheduler/migration_hook', 'pending' FROM DUAL
			WHERE ( SELECT NULL FROM DUAL ) IS NULL";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame( 1, $driver->get_insert_id() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO wptests_actionscheduler_actions ("hook", "status") SELECT \'action_scheduler/migration_hook\', \'pending\' WHERE (SELECT NULL) IS NULL',
			$queries[0]['sql']
		);
	}

	/**
	 * Tests explicit MySQL AUTO_INCREMENT values are exposed as the insert ID.
	 */
	public function test_get_insert_id_uses_explicit_mysql_auto_increment_value(): void {
		$driver = $this->create_driver_with_stale_connection_insert_id();

		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY AUTOINCREMENT,
				post_title TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_title varchar(255) NOT NULL DEFAULT "",
				PRIMARY KEY (ID)
			)'
		);

		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_title`) VALUES (587, 'explicit')" );

		$this->assertSame( 587, $driver->get_insert_id() );
		$rows = $driver->query( 'SELECT ID, post_title FROM wptests_posts WHERE ID = 587' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'explicit', $rows[0]->post_title );
	}

	/**
	 * Tests upsert conflict updates expose explicit AUTO_INCREMENT values as the insert ID.
	 */
	public function test_get_insert_id_returns_explicit_auto_increment_value_for_upsert_conflict_update(): void {
		$driver = $this->create_driver_with_stale_connection_insert_id();

		$this->install_identity_upsert_table_with_mysql_metadata( $driver );
		$driver->get_connection()->query( "INSERT INTO wptests_identity_upsert (id, value) VALUES (7, 'existing')" );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (7, 'updated')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame( 7, $driver->get_insert_id() );

		$rows = $driver->query( 'SELECT value FROM wptests_identity_upsert WHERE id = 7' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'updated', $rows[0]->value );
	}

	/**
	 * Tests inserts into tables without AUTO_INCREMENT do not expose stale IDs.
	 */
	public function test_get_insert_id_is_zero_for_non_auto_increment_insert(): void {
		$driver = $this->create_driver_with_stale_connection_insert_id();

		$driver->query(
			'CREATE TABLE wptests_term_relationships (
				object_id INTEGER NOT NULL DEFAULT 0,
				term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
				term_order INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_term_relationships (
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT 0,
				term_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id)
			)'
		);

		$driver->query( 'INSERT INTO wptests_term_relationships (`object_id`, `term_taxonomy_id`, `term_order`) VALUES (587, 1, 0)' );

		$this->assertSame( 0, $driver->get_insert_id() );
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

		$this->install_options_table_with_mysql_metadata( $driver );

		$unique_index_metadata_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$unique_index_metadata_queries ): void {
				if ( false !== strpos( $sql, "non_unique = '0'" ) ) {
					++$unique_index_metadata_queries;
				}
			}
		);

		$insert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.org', 'yes')
			ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`),
			                        `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`);";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame( $insert, $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.org\', \'yes\') ON CONFLICT ("option_name") DO UPDATE SET "option_name" = excluded."option_name", "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame( 1, $unique_index_metadata_queries );

		$update = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.net', 'no')
			ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`),
			                        `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`);";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.net\', \'no\') ON CONFLICT ("option_name") DO UPDATE SET "option_name" = excluded."option_name", "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame( 1, $unique_index_metadata_queries );

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'http://example.net', $rows[0]->option_value );
		$this->assertSame( 'no', $rows[0]->autoload );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT "",
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (option_id)
			)'
		);

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$update
			)
		);
		$this->assertSame( 2, $unique_index_metadata_queries );
	}

	/**
	 * Tests serialized feed option upserts quote PostgreSQL-safe text.
	 */
	public function test_options_upsert_quotes_serialized_feed_payload_for_postgresql(): void {
		$driver = $this->create_driver_with_postgresql_quote_translation();

		$this->install_options_table_with_mysql_metadata( $driver );

		$payload = serialize(
			array(
				"\0*\0data" => "single ' double \" backslash \\ marker E'\nnext line",
			)
		);
		$insert  = sprintf(
			"INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
				VALUES ('_transient_feed_quote_test', %s, 'off')
				ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`),
				                        `option_value` = VALUES(`option_value`),
				                        `autoload` = VALUES(`autoload`);",
			$this->quote_mysql_string_literal_for_test( $payload )
		);

		$translation = $this->translate_driver_query_data_with_private_method(
			$driver,
			'translate_mysql_on_duplicate_key_update_query',
			$insert
		);

		$this->assertIsArray( $translation );
		$sql = $translation['sql'];

		$this->assertStringNotContainsString( "\0", $sql );
		$this->assertStringContainsString( 'WP_MYSQL_TEXT_V1:', $sql );
		$this->assertStringContainsString( bin2hex( $payload ), $sql );
		$this->assertStringContainsString( '"option_value" = excluded."option_value"', $sql );
		$this->assertStringContainsString( 'ON CONFLICT ("option_name") DO UPDATE', $sql );
	}

	/**
	 * Tests metadata-backed multi-row ON DUPLICATE KEY UPDATE statements.
	 */
	public function test_multi_row_on_duplicate_key_update_uses_metadata_conflict_target(): void {
		$driver = $this->create_driver();

		$this->install_term_relationships_table_with_mysql_metadata( $driver, 'custom_term_relationships' );

		$insert = 'INSERT INTO `custom_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`)
			VALUES (227, 709, 1), (227, 710, 2)
			ON DUPLICATE KEY UPDATE `term_order` = VALUES(`term_order`)';

		$this->assertSame( 2, $driver->query( $insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "custom_term_relationships" ("object_id", "term_taxonomy_id", "term_order") VALUES (227, 709, 1), (227, 710, 2) ON CONFLICT ("object_id", "term_taxonomy_id") DO UPDATE SET "term_order" = excluded."term_order"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$upsert = 'INSERT INTO `custom_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`)
			VALUES (227, 709, 7), (227, 711, 3)
			ON DUPLICATE KEY UPDATE `term_order` = VALUES(`term_order`)';

		$this->assertSame( 2, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "custom_term_relationships" ("object_id", "term_taxonomy_id", "term_order") VALUES (227, 709, 7), (227, 711, 3) ON CONFLICT ("object_id", "term_taxonomy_id") DO UPDATE SET "term_order" = excluded."term_order"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query(
			'SELECT object_id, term_taxonomy_id, term_order
			FROM custom_term_relationships
			ORDER BY term_taxonomy_id'
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( '227', $rows[0]->object_id );
		$this->assertSame( '709', $rows[0]->term_taxonomy_id );
		$this->assertSame( '7', $rows[0]->term_order );
		$this->assertSame( '710', $rows[1]->term_taxonomy_id );
		$this->assertSame( '2', $rows[1]->term_order );
		$this->assertSame( '711', $rows[2]->term_taxonomy_id );
		$this->assertSame( '3', $rows[2]->term_order );
	}

	/**
	 * Tests ambiguous duplicate-key arbiters fail closed.
	 */
	public function test_ambiguous_on_duplicate_key_update_returns_null(): void {
		$driver = $this->create_driver();

		$this->install_ambiguous_upsert_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO ambiguous_upsert (id, slug, value) VALUES (1, 'existing', 'old')" );

		$upsert = "INSERT INTO `ambiguous_upsert` (`id`, `slug`, `value`) VALUES (2, 'existing', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$upsert
			)
		);

		$this->expectException( PDOException::class );

		$driver->query( $upsert );
	}

	/**
	 * Tests prefix unique duplicate-key arbiters fail closed.
	 */
	public function test_prefix_unique_on_duplicate_key_update_returns_null(): void {
		$driver = $this->create_driver();

		$this->install_prefix_ambiguous_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `prefix_ambiguous` (`id`, `slug`, `value`) VALUES (2, 'existing-slug', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$upsert
			)
		);
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
					'sql'    => 'UPDATE "wp_options" SET "option_value" = \'value2\' WHERE ("option_name" = \'key1\') AND ("option_value" IS DISTINCT FROM (\'value2\'))',
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
	 * Tests simple WordPress UPDATE statements return changed rows, not matched rows.
	 */
	public function test_simple_wordpress_update_returns_zero_for_noop_update(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wp_comments (
				comment_ID INTEGER PRIMARY KEY,
				comment_parent INTEGER NOT NULL,
				comment_content TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wp_comments (comment_ID, comment_parent, comment_content) VALUES (1, 0, 'first')" );

		$update = "UPDATE `wp_comments` SET `comment_parent` = 2, `comment_content` = 'updated' WHERE `comment_ID` = 1";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 0, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wp_comments" SET "comment_parent" = 2, "comment_content" = \'updated\' WHERE ("comment_ID" = 1) AND ("comment_parent" IS DISTINCT FROM (2) OR "comment_content" IS DISTINCT FROM (\'updated\'))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT comment_parent, comment_content FROM wp_comments WHERE "comment_ID" = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->comment_parent );
		$this->assertSame( 'updated', $rows[0]->comment_content );
	}

	/**
	 * Tests simple UPDATE preserves a MySQL literal ending in an escaped backslash.
	 */
	public function test_simple_update_preserves_trailing_escaped_backslash_literal(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_commentmeta (
				comment_id TEXT NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES ('8', 'slash_test_2', 'foo')" );

		$expected_value      = 'String with 3 slashes ' . '\\';
		$mysql_literal_value = 'String with 3 slashes ' . '\\\\';
		$update              = "UPDATE `wptests_commentmeta` SET `meta_value` = '{$mysql_literal_value}' WHERE `comment_id` = '8' AND `meta_key` = 'slash_test_2'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_commentmeta" SET "meta_value" = ' . $driver->get_connection()->quote( $expected_value ) . ' WHERE ("comment_id" = \'8\' AND "meta_key" = \'slash_test_2\') AND ("meta_value" IS DISTINCT FROM (' . $driver->get_connection()->quote( $expected_value ) . '))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT meta_value FROM wptests_commentmeta WHERE comment_id = '8' AND meta_key = 'slash_test_2'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( $expected_value, $rows[0]->meta_value );
	}

	/**
	 * Tests placeholder-like bytes in literal-only UPDATE statements remain data.
	 */
	public function test_simple_update_literal_placeholder_bytes_are_not_bound_parameters(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_commentmeta (
				comment_id TEXT NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES ('8', 'slash_test_2', 'foo')" );

		$expected_value      = 'literal ? :name ::text ' . '\\';
		$mysql_literal_value = 'literal ? :name ::text ' . '\\\\';
		$update              = "UPDATE `wptests_commentmeta` SET `meta_value` = '{$mysql_literal_value}' WHERE `comment_id` = '8' AND `meta_key` = 'slash_test_2'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_commentmeta" SET "meta_value" = ' . $driver->get_connection()->quote( $expected_value ) . ' WHERE ("comment_id" = \'8\' AND "meta_key" = \'slash_test_2\') AND ("meta_value" IS DISTINCT FROM (' . $driver->get_connection()->quote( $expected_value ) . '))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT meta_value FROM wptests_commentmeta WHERE comment_id = '8' AND meta_key = 'slash_test_2'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( $expected_value, $rows[0]->meta_value );
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
					'sql'    => 'UPDATE "wptests_options" SET "option_value" = \'\', "autoload" = \'yes\' WHERE ("option_name" = \'cron\') AND ("option_value" IS DISTINCT FROM (\'\') OR "autoload" IS DISTINCT FROM (\'yes\'))',
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
	 * Tests non-strict UPDATE normalizes invalid date/time literals using MySQL metadata.
	 */
	public function test_non_strict_update_normalizes_invalid_date_time_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_date_gmt, post_modified, post_modified_gmt)
			VALUES (1, '2020-01-01 01:02:03', '2020-01-01 01:02:03', '2020-01-01 01:02:03', '2020-01-01 01:02:03')"
		);

		$update = "UPDATE `wptests_posts` SET `post_date_gmt` = '2020-02-31 14:15:27', `post_modified_gmt` = '2020-07-04T01:02:03Z' WHERE `ID` = 1";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_posts" SET "post_date_gmt" = \'0000-00-00 00:00:00\', "post_modified_gmt" = \'2020-07-04 01:02:03\' WHERE ("ID" = 1) AND ("post_date_gmt" IS DISTINCT FROM (\'0000-00-00 00:00:00\') OR "post_modified_gmt" IS DISTINCT FROM (\'2020-07-04 01:02:03\'))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$posts = $driver->query( 'SELECT post_date_gmt, post_modified_gmt FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $posts );
		$this->assertSame( '0000-00-00 00:00:00', $posts[0]->post_date_gmt );
		$this->assertSame( '2020-07-04 01:02:03', $posts[0]->post_modified_gmt );
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
	 * Tests WooCommerce orphan cleanup DELETE statements are translated to anti-joins.
	 */
	public function test_mysql_left_join_orphan_delete_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id INTEGER PRIMARY KEY,
				post_id INTEGER NOT NULL
			)'
		);
		$driver->query( 'INSERT INTO wptests_posts ("ID") VALUES (1)' );
		$driver->query( 'INSERT INTO wptests_postmeta (meta_id, post_id) VALUES (1, 1)' );
		$driver->query( 'INSERT INTO wptests_postmeta (meta_id, post_id) VALUES (2, 999)' );

		$delete = 'DELETE meta FROM wptests_postmeta meta LEFT JOIN wptests_posts posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;';

		$this->assertSame( 1, $driver->query( $delete ) );
		$this->assertSame( $delete, $driver->get_last_mysql_query() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'DELETE FROM "wptests_postmeta" AS meta WHERE NOT EXISTS (SELECT 1 FROM "wptests_posts" AS posts WHERE posts."ID" = meta.post_id)',
			$queries[0]['sql']
		);

		$rows = $driver->query( 'SELECT meta_id, post_id FROM wptests_postmeta ORDER BY meta_id' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->meta_id );
		$this->assertSame( '1', $rows[0]->post_id );
	}

	/**
	 * Tests MySQL joined DELETE statements with AS aliases are translated.
	 */
	public function test_mysql_join_delete_with_as_alias_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$delete = "DELETE `postmeta` FROM `wptests_postmeta` AS `postmeta`
			LEFT JOIN `wptests_posts` AS `posts` ON `posts`.`ID` = `postmeta`.`post_id`
			WHERE `posts`.`post_type` = 'forum'
			AND `postmeta`.`meta_key` = '_bbp_reply_count'
			OR `postmeta`.`meta_key` = '_bbp_total_reply_count'";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_single_target_join_delete_query',
			$delete
		);

		$this->assertSame(
			'DELETE FROM "wptests_postmeta" AS "postmeta" WHERE "postmeta".ctid IN (SELECT "postmeta".ctid FROM "wptests_postmeta" AS "postmeta" LEFT JOIN "wptests_posts" AS "posts" ON "posts"."ID" = "postmeta"."post_id" WHERE "posts"."post_type" = \'forum\' AND "postmeta"."meta_key" = \'_bbp_reply_count\' OR "postmeta"."meta_key" = \'_bbp_total_reply_count\')',
			$sql
		);
	}

	/**
	 * Tests MySQL DUAL table references are erased in PostgreSQL-compatible SELECTs.
	 */
	public function test_mysql_dual_table_reference_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SELECT 1 AS output FROM DUAL' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->output );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT 1 AS output',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests MySQL DUAL table references are erased in INSERT ... SELECT queries.
	 */
	public function test_insert_select_from_dual_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_actionscheduler_actions (
				hook TEXT NOT NULL,
				status TEXT NOT NULL
			)'
		);

		$insert = "INSERT INTO wptests_actionscheduler_actions (`hook`, `status`)
			SELECT 'action_scheduler/migration_hook', 'pending' FROM DUAL
			WHERE ( SELECT NULL FROM DUAL ) IS NULL";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame(
			'INSERT INTO wptests_actionscheduler_actions ("hook", "status") SELECT \'action_scheduler/migration_hook\', \'pending\' WHERE (SELECT NULL) IS NULL',
			$queries[0]['sql']
		);

		$rows = $driver->query( 'SELECT hook, status FROM wptests_actionscheduler_actions' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'action_scheduler/migration_hook', $rows[0]->hook );
		$this->assertSame( 'pending', $rows[0]->status );
	}

	/**
	 * Tests bbPress-style INSERT ... SELECT repair queries coerce target types.
	 */
	public function test_parenthesized_insert_select_coerces_target_columns_and_grouped_projections(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY,
				post_parent INTEGER NOT NULL,
				post_author INTEGER NOT NULL,
				post_type TEXT NOT NULL,
				post_status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
				`post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
				`post_type` varchar(20) NOT NULL DEFAULT "",
				`post_status` varchar(20) NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_postmeta (
				`meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`post_id` bigint(20) unsigned NOT NULL DEFAULT 0,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL,
				PRIMARY KEY (`meta_id`)
			)'
		);

		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `post_author`, `post_type`, `post_status`) VALUES (10, 0, 1, 'forum', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `post_author`, `post_type`, `post_status`) VALUES (100, 10, 3, 'topic', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `post_author`, `post_type`, `post_status`) VALUES (101, 100, 4, 'reply', 'publish')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (100, '_bbp_topic_id', '100')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (101, '_bbp_topic_id', '100')" );

		$engagements_sql = "INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) (
			SELECT postmeta.meta_value, '_bbp_engagement', posts.post_author
			FROM wptests_posts AS posts
			LEFT JOIN wptests_postmeta AS postmeta
				ON posts.ID = postmeta.post_id
				AND postmeta.meta_key = '_bbp_topic_id'
			WHERE posts.post_type IN ('topic', 'reply')
				AND posts.post_status IN ('publish', 'closed')
			GROUP BY postmeta.meta_value, posts.post_author)";

		$this->assertSame( 2, $driver->query( $engagements_sql ) );

		$engagement_queries = $driver->get_last_postgresql_queries();
		$engagement_sql     = $engagement_queries[0]['sql'];
		$this->assertStringContainsString( 'CASE WHEN CAST(postmeta.meta_value AS text) IS NULL THEN NULL ELSE CAST(COALESCE(SUBSTRING(CAST(postmeta.meta_value AS text)', $engagement_sql );
		$this->assertStringContainsString( 'CAST(posts.post_author AS text)', $engagement_sql );

		$engagements = $driver->query( "SELECT post_id, meta_key, meta_value FROM wptests_postmeta WHERE meta_key = '_bbp_engagement' ORDER BY meta_value" );
		$this->assertCount( 2, $engagements );
		$this->assertSame( '100', $engagements[0]->post_id );
		$this->assertSame( '3', $engagements[0]->meta_value );
		$this->assertSame( '100', $engagements[1]->post_id );
		$this->assertSame( '4', $engagements[1]->meta_value );

		$forum_meta_sql = "INSERT INTO `wptests_postmeta` (`post_id`, `meta_key`, `meta_value`)
			( SELECT `reply`.`ID`, '_bbp_forum_id', `topic`.`post_parent`
			FROM `wptests_posts`
				AS `reply`
			INNER JOIN `wptests_posts`
				AS `topic`
				ON `reply`.`post_parent` = `topic`.`ID`
			WHERE `topic`.`post_type` = 'topic'
				AND `reply`.`post_type` = 'reply'
			GROUP BY `reply`.`ID` )";

		$this->assertSame( 1, $driver->query( $forum_meta_sql ) );

		$forum_meta_queries = $driver->get_last_postgresql_queries();
		$this->assertStringContainsString(
			'CAST(MIN("topic"."post_parent") AS text)',
			$forum_meta_queries[0]['sql']
		);

		$forum_meta = $driver->query( "SELECT post_id, meta_value FROM wptests_postmeta WHERE meta_key = '_bbp_forum_id'" );
		$this->assertCount( 1, $forum_meta );
		$this->assertSame( '101', $forum_meta[0]->post_id );
		$this->assertSame( '10', $forum_meta[0]->meta_value );

		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `post_author`, `post_type`, `post_status`) VALUES (102, 100, 5, 'reply', 'spam')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `post_author`, `post_type`, `post_status`) VALUES (103, 100, 6, 'reply', 'pending')" );
		$hidden_reply_count_sql = "INSERT INTO `wptests_postmeta` (`post_id`, `meta_key`, `meta_value`)
			(SELECT `post_parent`, '_bbp_reply_count_hidden', COUNT(`post_status`) as `meta_value`
			FROM `wptests_posts`
			WHERE `post_type` = 'reply'
				AND `post_status` IN ('trash','spam','pending')
			GROUP BY `post_parent`)";

		$this->assertSame( 1, $driver->query( $hidden_reply_count_sql ) );

		$hidden_reply_count_queries = $driver->get_last_postgresql_queries();
		$this->assertStringContainsString(
			'CAST(COUNT ("post_status") AS text)',
			$hidden_reply_count_queries[0]['sql']
		);
		$this->assertStringNotContainsString( 'AS "meta_value" AS text', $hidden_reply_count_queries[0]['sql'] );

		$hidden_reply_count = $driver->query( "SELECT post_id, meta_value FROM wptests_postmeta WHERE meta_key = '_bbp_reply_count_hidden'" );
		$this->assertCount( 1, $hidden_reply_count );
		$this->assertSame( '100', $hidden_reply_count[0]->post_id );
		$this->assertSame( '2', $hidden_reply_count[0]->meta_value );
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
					'sql'    => 'UPDATE "wp_options" SET "option_value" = \'value2\', "autoload" = \'yes\' WHERE ("option_name" = \'key1\') AND ("option_value" IS DISTINCT FROM (\'value2\') OR "autoload" IS DISTINCT FROM (\'yes\'))',
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
	 * Tests CONVERT(expr, SIGNED) expressions use MySQL integer coercion.
	 */
	public function test_convert_signed_expression_uses_mysql_integer_coercion(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query( 'CREATE TABLE wptests_bp_groups_groupmeta (meta_value TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_bp_groups_groupmeta (meta_value) VALUES ('10members')" );

		$rows = $driver->query(
			'SELECT CONVERT(meta_value, SIGNED) AS member_count
			FROM wptests_bp_groups_groupmeta
			ORDER BY CONVERT(meta_value, SIGNED) DESC'
		);

		$meta_value_cast_sql = $this->get_expected_mysql_integer_cast_sql( 'meta_value' );
		$this->assertSame( '10', $rows[0]->member_count );
		$this->assertStringContainsString(
			'SELECT ' . $meta_value_cast_sql . ' AS member_count',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
		$this->assertStringContainsString(
			'ORDER BY ' . $meta_value_cast_sql . ' DESC',
			$driver->get_last_postgresql_queries()[0]['sql']
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
	 * Tests WordPress sticky base queries get MySQL's posts date ID tie-breaker.
	 */
	public function test_wordpress_posts_post_date_desc_order_uses_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		for ( $id = 1; $id <= 5; $id++ ) {
			$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES ($id, 'post', 'publish', '2024-01-01 00:00:00')" );
		}

		$select = "SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE 1 = 1 AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 5";
		$rows   = $driver->query( $select );

		$this->assertSame(
			array( '5', '4', '3', '2', '1' ),
			array_map(
				static function ( $row ) {
					return $row->ID;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts."ID", COUNT(*) OVER() AS "__wp_pg_found_rows" FROM wptests_posts WHERE 1 = 1 AND ((wptests_posts.post_type = \'post\' AND (wptests_posts.post_status = \'publish\'))) ORDER BY wptests_posts.post_date DESC, wptests_posts."ID" DESC LIMIT 5 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests leading-comment SELECTs still use the SELECT translator chain.
	 */
	public function test_leading_comment_select_uses_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		for ( $id = 1; $id <= 3; $id++ ) {
			$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES ($id, 'post', 'publish', '2024-01-01 00:00:00')" );
		}

		$select = "/* cache gate */ SELECT wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post' AND wptests_posts.post_status = 'publish'
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 3";
		$rows   = $driver->query( $select );

		$this->assertSame(
			array( '3', '2', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts."ID" FROM wptests_posts WHERE wptests_posts.post_type = \'post\' AND wptests_posts.post_status = \'publish\' ORDER BY wptests_posts.post_date DESC, wptests_posts."ID" DESC LIMIT 3 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests non-descending posts date order does not get the sticky tie-breaker.
	 */
	public function test_wordpress_posts_post_date_asc_order_does_not_add_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (1, \'post\', \'publish\', \'2024-01-01 00:00:00\')' );

		$select = "SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post'
			ORDER BY wptests_posts.post_date ASC
			LIMIT 0, 5";
		$driver->query( $select );

		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts."ID", COUNT(*) OVER() AS "__wp_pg_found_rows" FROM wptests_posts WHERE wptests_posts.post_type = \'post\' ORDER BY wptests_posts.post_date ASC LIMIT 5 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests grouped posts post_date DESC order keeps MySQL's ID tie-breaker.
	 */
	public function test_wordpress_grouped_posts_post_date_desc_order_uses_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		for ( $id = 1; $id <= 3; $id++ ) {
			$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES ($id, 'post', 'publish', '2024-01-01 00:00:00')" );
		}

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post' AND wptests_posts.post_status = 'publish'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 3"
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
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT wptests_posts."ID" FROM wptests_posts WHERE wptests_posts.post_type = \'post\' AND wptests_posts.post_status = \'publish\' GROUP BY wptests_posts."ID" ORDER BY MAX(wptests_posts.post_date) DESC, wptests_posts."ID" DESC LIMIT 3 OFFSET 0',
					'params' => array(),
				),
				array(
					'sql'    => 'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM (SELECT wptests_posts."ID" FROM wptests_posts WHERE wptests_posts.post_type = \'post\' AND wptests_posts.post_status = \'publish\' GROUP BY wptests_posts."ID") AS "__wp_pg_found_rows"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests admin page search ordering uses MySQL-compatible ID tie-breakers.
	 */
	public function test_wordpress_admin_page_search_menu_order_title_order_uses_id_tiebreaker(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
				`menu_order` int(11) NOT NULL DEFAULT 0,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_excerpt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				`post_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				`post_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `menu_order`, `post_title`, `post_excerpt`, `post_content`, `post_password`, `post_type`, `post_status`) VALUES (12, 5, 0, 'Child 1', '', '', '', 'page', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `menu_order`, `post_title`, `post_excerpt`, `post_content`, `post_password`, `post_type`, `post_status`) VALUES (9, 4, 0, 'Child 1', '', '', '', 'page', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_parent`, `menu_order`, `post_title`, `post_excerpt`, `post_content`, `post_password`, `post_type`, `post_status`) VALUES (10, 4, 0, 'Child 2', '', '', '', 'page', 'publish')" );

		$rows = $driver->query(
			"SELECT wptests_posts.*
			FROM wptests_posts
			WHERE 1=1
				AND (((wptests_posts.post_title LIKE '%Child%') OR (wptests_posts.post_excerpt LIKE '%Child%') OR (wptests_posts.post_content LIKE '%Child%')))
				AND (wptests_posts.post_password = '')
				AND ((wptests_posts.post_type = 'page' AND (wptests_posts.post_status = 'publish')))
			ORDER BY wptests_posts.menu_order ASC, wptests_posts.post_title ASC"
		);

		$this->assertSame(
			array( '9', '12', '10' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$rows
			)
		);
		$this->assertStringContainsString(
			'ORDER BY wptests_posts.menu_order ASC, LOWER(wptests_posts.post_title) ASC, wptests_posts."ID" ASC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests available post MIME type lookups use MySQL-compatible first posts.ID ordering.
	 */
	public function test_wordpress_available_post_mime_types_distinct_orders_by_first_post_id(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_mime_type`) VALUES (1, 'attachment', 'image/jpeg')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_mime_type`) VALUES (2, 'attachment', 'application/pdf')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_mime_type`) VALUES (3, 'attachment', 'image/jpeg')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_mime_type`) VALUES (4, 'post', 'text/plain')" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_mime_type`) VALUES (5, 'attachment', '')" );

		$rows = $driver->query(
			"SELECT DISTINCT post_mime_type
			FROM wptests_posts
			WHERE post_type = 'attachment' AND post_mime_type != ''"
		);

		$this->assertSame(
			array( 'image/jpeg', 'application/pdf' ),
			array_map(
				static function ( $row ): string {
					return $row->post_mime_type;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT post_mime_type FROM wptests_posts WHERE post_type = \'attachment\' AND post_mime_type != \'\' GROUP BY post_mime_type ORDER BY MIN("ID") ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests broader posts DISTINCT MIME type queries keep the generic path.
	 */
	public function test_wordpress_available_post_mime_types_distinct_rewrite_requires_exact_where_shape(): void {
		$driver = $this->create_driver();
		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_wordpress_available_post_mime_types_query',
			"SELECT DISTINCT post_mime_type FROM wptests_posts WHERE post_type = 'attachment'"
		);

		$this->assertNull( $sql );
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

		$queries = $driver->get_last_postgresql_queries();
		$sql     = $queries[0]['sql'];
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $sql );
		$this->assertStringContainsString(
			'"ID" = ' . $this->get_expected_mysql_integer_cast_sql( "'yololololo'" ),
			$sql
		);
		$this->assertStringContainsString( "LOWER(user_login) LIKE LOWER('%yololololo%')", $sql );
		$this->assertStringContainsString(
			'COUNT(*) OVER() AS "__wp_pg_found_rows"',
			$sql
		);
	}

	/**
	 * Tests SQL_CALC_FOUND_ROWS user searches coerce bad ID terms after author subqueries.
	 */
	public function test_sql_calc_found_rows_user_search_coerces_integer_id_string_predicate_after_subquery(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_users (
				`ID` bigint(20) unsigned NOT NULL,
				`user_login` varchar(60) NOT NULL DEFAULT "",
				`user_nicename` varchar(50) NOT NULL DEFAULT "",
				`display_name` varchar(250) NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
				`post_status` varchar(20) NOT NULL DEFAULT "publish",
				`post_type` varchar(20) NOT NULL DEFAULT "post",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'INSERT INTO wptests_users (`ID`, `user_login`, `user_nicename`, `display_name`) ' .
			'VALUES (1, \'admin\', \'admin\', \'Admin\')'
		);
		$driver->query(
			'INSERT INTO wptests_users (`ID`, `user_login`, `user_nicename`, `display_name`) ' .
			'VALUES (2, \'match-yololololo\', \'match-yololololo\', \'Match Yololololo\')'
		);
		$driver->query(
			'INSERT INTO wptests_posts (`ID`, `post_author`, `post_status`, `post_type`) ' .
			'VALUES (1, 2, \'publish\', \'post\')'
		);

		$select = "SELECT SQL_CALC_FOUND_ROWS wptests_users.ID
			FROM wptests_users
			WHERE 1=1
				AND wptests_users.ID IN (
					SELECT DISTINCT wptests_posts.post_author
					FROM wptests_posts
					WHERE wptests_posts.post_status = 'publish'
						AND wptests_posts.post_type IN ( 'post', 'page' )
				)
				AND (ID = 'yololololo'
					OR user_login LIKE '%yololololo%'
					OR user_nicename LIKE '%yololololo%'
					OR display_name LIKE '%yololololo%')
			ORDER BY display_name ASC
			LIMIT 0, 10";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->ID );

		$queries = $driver->get_last_postgresql_queries();
		foreach ( $queries as $query ) {
			$sql = $query['sql'];
			$this->assertStringContainsString(
				'"ID" = ' . $this->get_expected_mysql_integer_cast_sql( "'yololololo'" ),
				$sql
			);
			$this->assertStringContainsString( "LOWER(user_login) LIKE LOWER('%yololololo%')", $sql );
			$this->assertStringContainsString( "LOWER(user_nicename) LIKE LOWER('%yololololo%')", $sql );
			$this->assertStringContainsString( "LOWER(display_name) LIKE LOWER('%yololololo%')", $sql );
		}
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
	 * Tests text metadata UPDATE additions use MySQL numeric coercion before text assignment.
	 */
	public function test_text_metadata_update_addition_uses_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_postmeta (
				`post_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, '_order_total', '10')" );

		$this->assertSame(
			1,
			$driver->query( "UPDATE wptests_postmeta SET meta_value = meta_value + 4.000000 WHERE post_id = 1 AND meta_key = '_order_total'" )
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'meta_value' );
		$this->assertStringContainsString(
			'"meta_value" = CAST(' . $meta_value_cast_sql . ' + 4.000000 AS text)',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$rows = $driver->query( "SELECT meta_value FROM wptests_postmeta WHERE post_id = 1 AND meta_key = '_order_total'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '14.0', $rows[0]->meta_value );
	}

	/**
	 * Tests text metadata UPDATE subtractions use MySQL numeric coercion.
	 */
	public function test_text_metadata_update_subtraction_uses_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_usermeta (
				`user_id` bigint(20) unsigned NOT NULL,
				`meta_key` varchar(255) NOT NULL DEFAULT "",
				`meta_value` longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_usermeta (`user_id`, `meta_key`, `meta_value`) VALUES (107, 'total_group_count', '7')" );

		$this->assertSame(
			1,
			$driver->query( "UPDATE wptests_usermeta SET meta_value = meta_value - 1 WHERE meta_key = 'total_group_count' AND user_id IN ( 107 )" )
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'meta_value' );
		$this->assertStringContainsString(
			'"meta_value" = CAST(' . $meta_value_cast_sql . ' - 1 AS text)',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$rows = $driver->query( "SELECT meta_value FROM wptests_usermeta WHERE user_id = 107 AND meta_key = 'total_group_count'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '6', $rows[0]->meta_value );
	}

	/**
	 * Tests text metadata SUM aggregates use MySQL numeric coercion from metadata.
	 */
	public function test_text_metadata_sum_aggregate_uses_mysql_numeric_coercion_from_metadata(): void {
		$driver = $this->create_driver_with_postgresql_substring_function();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_type` varchar(20) NOT NULL DEFAULT "",
				`post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
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
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_parent`) VALUES (2, 'shop_order_refund', 1)" );
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_parent`) VALUES (3, 'shop_order_refund', 1)" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, '_refund_amount', '2.25')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, '_refund_amount', '3')" );

		$rows = $driver->query(
			"SELECT SUM( postmeta.meta_value ) AS refunded
				FROM wptests_postmeta AS postmeta
				INNER JOIN wptests_posts AS posts ON ( posts.post_type = 'shop_order_refund' AND posts.post_parent = 1 )
				WHERE postmeta.meta_key = '_refund_amount'
				AND postmeta.post_id = posts.ID"
		);

		$meta_value_cast_sql = $this->get_expected_mysql_numeric_cast_sql( 'postmeta.meta_value' );
		$this->assertStringContainsString(
			'SUM(' . $meta_value_cast_sql . ') AS refunded',
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '5.25', $rows[0]->refunded );
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
	 * Tests WordPress term and post-search predicates preserve MySQL case-insensitive collation behavior.
	 */
	public function test_wordpress_term_and_post_search_text_predicates_use_case_insensitive_mysql_collation_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_excerpt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				`post_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_terms (
				`term_id` bigint(20) unsigned NOT NULL,
				`name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`slug` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`term_id`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_term_taxonomy (
				`term_taxonomy_id` bigint(20) unsigned NOT NULL,
				`term_id` bigint(20) unsigned NOT NULL,
				`taxonomy` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				PRIMARY KEY (`term_taxonomy_id`)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_posts (`ID`, `post_title`, `post_excerpt`, `post_content`) VALUES (7, 'Search & Test', '', 'Body')"
		);
		$driver->query( "INSERT INTO wptests_terms (`term_id`, `name`, `slug`) VALUES (1, 'burrito', 'burrito')" );
		$driver->query( "INSERT INTO wptests_terms (`term_id`, `name`, `slug`) VALUES (2, 'taco', 'taco')" );
		$driver->query(
			"INSERT INTO wptests_term_taxonomy (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`) VALUES (10, 1, 'post_tag', 'This is a burrito.')"
		);
		$driver->query(
			"INSERT INTO wptests_term_taxonomy (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`) VALUES (20, 2, 'post_tag', 'Burning man.')"
		);

		$post_rows = $driver->query(
			"SELECT ID
			FROM wptests_posts
			WHERE wptests_posts.post_title LIKE '%test%'"
		);

		$this->assertSame(
			array( '7' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$post_rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(wptests_posts.post_title) LIKE LOWER('%test%')",
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$name_rows = $driver->query(
			"SELECT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'post_tag' AND t.name = 'BURRITO'"
		);

		$this->assertSame(
			array( '1' ),
			array_map(
				static function ( $row ): string {
					return $row->term_id;
				},
				$name_rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(t.name) = LOWER('BURRITO')",
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$name_in_rows = $driver->query(
			"SELECT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'post_tag' AND t.name IN ('BURRITO')"
		);

		$this->assertSame(
			array( '1' ),
			array_map(
				static function ( $row ): string {
					return $row->term_id;
				},
				$name_in_rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(t.name) IN (LOWER('BURRITO'))",
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$description_rows = $driver->query(
			"SELECT t.term_id
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy IN ('post_tag') AND tt.description LIKE '%Bur%'
			ORDER BY t.term_id ASC"
		);

		$this->assertSame(
			array( '1', '2' ),
			array_map(
				static function ( $row ): string {
					return $row->term_id;
				},
				$description_rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(tt.description) LIKE LOWER('%Bur%')",
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests column-reference metadata lookups are cached until table metadata changes.
	 */
	public function test_wordpress_column_reference_metadata_cache_reuses_lookups_until_metadata_changes(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_terms (
				`term_id` bigint(20) unsigned NOT NULL,
				`name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`term_id`)
			)'
		);

		$column_type_queries        = 0;
		$column_collation_queries   = 0;
		$table_has_metadata_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$column_type_queries, &$column_collation_queries, &$table_has_metadata_queries ): void {
				if ( false !== strpos( $sql, 'SELECT column_type FROM "__wp_postgresql_mysql_column_metadata"' ) ) {
					++$column_type_queries;
				}
				if ( false !== strpos( $sql, 'SELECT collation_name FROM "__wp_postgresql_mysql_column_metadata"' ) ) {
					++$column_collation_queries;
				}
				if ( false !== strpos( $sql, 'SELECT 1 FROM "__wp_postgresql_mysql_column_metadata"' ) ) {
					++$table_has_metadata_queries;
				}
			}
		);

		$query = "SELECT post_title FROM wptests_posts, wptests_terms WHERE post_title LIKE '%test%'";

		$driver->query( $query );

		$type_queries_after_first      = $column_type_queries;
		$collation_queries_after_first = $column_collation_queries;
		$metadata_queries_after_first  = $table_has_metadata_queries;
		$this->assertGreaterThan( 0, $type_queries_after_first );
		$this->assertGreaterThan( 0, $collation_queries_after_first );
		$this->assertGreaterThan( 0, $metadata_queries_after_first );

		$driver->query( $query );

		$this->assertSame( $type_queries_after_first, $column_type_queries );
		$this->assertSame( $collation_queries_after_first, $column_collation_queries );
		$this->assertSame( $metadata_queries_after_first, $table_has_metadata_queries );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( $query );

		$this->assertGreaterThan( $type_queries_after_first, $column_type_queries );
		$this->assertGreaterThan( $collation_queries_after_first, $column_collation_queries );
		$this->assertGreaterThan( $metadata_queries_after_first, $table_has_metadata_queries );
	}

	/**
	 * Tests qualified column-name metadata lookups are cached until table metadata changes.
	 */
	public function test_wordpress_column_name_metadata_cache_reuses_lookups_until_metadata_changes(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);

		$column_name_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$column_name_queries ): void {
				if ( false !== strpos( $sql, 'SELECT column_name FROM "__wp_postgresql_mysql_column_metadata"' ) ) {
					++$column_name_queries;
				}
			}
		);

		$query = 'SELECT p.ID FROM wptests_posts AS p WHERE p.ID > 0';

		$driver->query( $query );
		$column_name_queries_after_first = $column_name_queries;
		$this->assertGreaterThan( 0, $column_name_queries_after_first );

		$driver->query( $query );
		$this->assertSame( $column_name_queries_after_first, $column_name_queries );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( $query );
		$this->assertGreaterThan( $column_name_queries_after_first, $column_name_queries );
	}

	/**
	 * Tests exact SELECT translations are cached until table metadata changes.
	 */
	public function test_select_translation_cache_reuses_exact_sql_until_metadata_changes(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (ID, post_title) VALUES (1, 'Hello')" );

		$query = "SELECT p.ID FROM wptests_posts AS p WHERE p.ID > '0'";

		$driver->query( $query );

		$cache = $this->get_driver_private_property( $driver, 'mysql_select_translation_cache' );
		$this->assertCount( 1, $cache );

		$entry = reset( $cache );
		$this->assertIsArray( $entry );
		$this->assertSame( $query, $entry['query'] );
		$this->assertTrue( $entry['translated'] );
		$this->assertStringContainsString( 'p."ID"', $entry['sql'] );

		$driver->query( $query );

		$this->assertSame(
			$cache,
			$this->get_driver_private_property( $driver, 'mysql_select_translation_cache' )
		);

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);

		$this->assertSame(
			array(),
			$this->get_driver_private_property( $driver, 'mysql_select_translation_cache' )
		);
	}

	/**
	 * Tests exact SQL_CALC_FOUND_ROWS count SQL is cached until table metadata changes.
	 */
	public function test_sql_calc_found_rows_count_query_cache_reuses_exact_sql_until_metadata_changes(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query( "INSERT INTO wptests_posts (ID, post_title) VALUES (1, 'Hello')" );
		$driver->query( "INSERT INTO wptests_posts (ID, post_title) VALUES (2, 'World')" );

		$query = "SELECT SQL_CALC_FOUND_ROWS p.ID
			FROM wptests_posts AS p
			WHERE p.ID > '0'
			ORDER BY p.ID ASC
			LIMIT 10, 1";

		$driver->query( $query );

		$count_cache = $this->get_driver_private_property( $driver, 'mysql_sql_calc_found_rows_count_query_cache' );
		$this->assertCount( 1, $count_cache );

		$entry = reset( $count_cache );
		$this->assertIsArray( $entry );
		$this->assertSame( $query, $entry['query'] );
		$this->assertStringStartsWith( 'SELECT COUNT(*) AS "__wp_pg_found_rows"', $entry['sql'] );

		$postgresql_queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $postgresql_queries );
		$count_sql = $postgresql_queries[1]['sql'];

		$driver->query( $query );

		$this->assertSame(
			$count_cache,
			$this->get_driver_private_property( $driver, 'mysql_sql_calc_found_rows_count_query_cache' )
		);
		$this->assertSame( $count_sql, $driver->get_last_postgresql_queries()[1]['sql'] );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);

		$this->assertSame(
			array(),
			$this->get_driver_private_property( $driver, 'mysql_sql_calc_found_rows_count_query_cache' )
		);
	}

	/**
	 * Tests MySQL DATETIME casts in grouped postmeta ordering are translated.
	 */
	public function test_sql_calc_grouped_postmeta_order_by_datetime_cast_uses_postgresql_timestamp(): void {
		$driver = $this->create_driver();

		$translation = $this->translate_driver_query_data_with_private_method(
			$driver,
			'translate_mysql_select_query_for_postgresql',
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE wptests_postmeta.meta_key = '_bbp_last_active_time'
			AND wptests_posts.post_type = 'topic'
			AND wptests_posts.post_status = 'publish'
			GROUP BY wptests_posts.ID
			ORDER BY CAST(wptests_postmeta.meta_value AS DATETIME) DESC
			LIMIT 0, 15"
		);

		$this->assertIsArray( $translation );
		$this->assertTrue( $translation['translated'] );
		$sql = $translation['sql'];
		$this->assertStringContainsString( 'ORDER BY MAX(CAST(CASE WHEN', $sql );
		$this->assertStringContainsString( 'AS timestamp)) DESC', $sql );
		$this->assertStringNotContainsString( ' AS DATETIME', $sql );
	}

	/**
	 * Tests WordPress user text predicates and ordering preserve MySQL collation behavior.
	 */
	public function test_wordpress_user_text_predicates_and_ordering_use_case_insensitive_mysql_collation_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_users (
				`ID` bigint(20) unsigned NOT NULL,
				`user_login` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`user_nicename` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`user_email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`user_url` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`display_name` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_users (`ID`, `user_login`, `user_nicename`, `user_email`, `user_url`, `display_name`) VALUES (2, 'subscriber', 'subscriber', 'subscriber@example.com', '', 'subscriber')"
		);
		$driver->query(
			"INSERT INTO wptests_users (`ID`, `user_login`, `user_nicename`, `user_email`, `user_url`, `display_name`) VALUES (33, 'zzzz', 'zzzz', 'zzzz@example.com', '', 'ZZZZ')"
		);

		$email_rows = $driver->query( "SELECT ID FROM wptests_users WHERE user_email = 'Subscriber@Example.com'" );

		$this->assertSame(
			array( '2' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$email_rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(user_email) = LOWER('Subscriber@Example.com')",
			$driver->get_last_postgresql_queries()[0]['sql']
		);

		$order_rows = $driver->query( 'SELECT ID FROM wptests_users ORDER BY display_name DESC LIMIT 1' );

		$this->assertStringContainsString(
			'ORDER BY LOWER(display_name) DESC',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
		$this->assertSame( '33', $order_rows[0]->ID );
	}

	/**
	 * Tests WordPress post-search relevance CASE ordering uses case-insensitive text predicates.
	 */
	public function test_wordpress_post_search_relevance_order_by_case_uses_case_insensitive_mysql_collation_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_posts (
				`ID` bigint(20) unsigned NOT NULL,
				`post_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				`post_excerpt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				`post_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
				PRIMARY KEY (`ID`)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_posts (`ID`, `post_title`, `post_excerpt`, `post_content`) VALUES (1, 'This post has foo', '', '')"
		);
		$driver->query(
			"INSERT INTO wptests_posts (`ID`, `post_title`, `post_excerpt`, `post_content`) VALUES (2, '', '', 'This post has foo')"
		);
		$driver->query(
			"INSERT INTO wptests_posts (`ID`, `post_title`, `post_excerpt`, `post_content`) VALUES (3, '', 'This post has foo', '')"
		);

		$rows = $driver->query(
			"SELECT ID
			FROM wptests_posts
			ORDER BY (CASE
				WHEN wptests_posts.post_title LIKE '%this post has foo%' THEN 1
				WHEN wptests_posts.post_title LIKE '%this%' AND wptests_posts.post_title LIKE '%post%' AND wptests_posts.post_title LIKE '%has%' AND wptests_posts.post_title LIKE '%foo%' THEN 2
				WHEN wptests_posts.post_title LIKE '%this%' OR wptests_posts.post_title LIKE '%post%' OR wptests_posts.post_title LIKE '%has%' OR wptests_posts.post_title LIKE '%foo%' THEN 3
				WHEN wptests_posts.post_excerpt LIKE '%this post has foo%' THEN 4
				WHEN wptests_posts.post_content LIKE '%this post has foo%' THEN 5
				ELSE 6
			END), wptests_posts.ID ASC"
		);

		$this->assertSame(
			array( '1', '3', '2' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$rows
			)
		);

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertStringContainsString(
			"WHEN LOWER(wptests_posts.post_title) LIKE LOWER('%this post has foo%') THEN 1",
			$sql
		);
		$this->assertStringContainsString(
			"WHEN LOWER(wptests_posts.post_excerpt) LIKE LOWER('%this post has foo%') THEN 4",
			$sql
		);
		$this->assertStringContainsString(
			"WHEN LOWER(wptests_posts.post_content) LIKE LOWER('%this post has foo%') THEN 5",
			$sql
		);
	}

	/**
	 * Tests schema-qualified WordPress text predicates do not rewrite qualified-reference suffixes.
	 */
	public function test_schema_qualified_wordpress_text_predicates_fail_closed_without_suffix_rewrite(): void {
		$driver = $this->create_driver();
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_terms (
				`term_id` bigint(20) unsigned NOT NULL,
				`name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "",
				PRIMARY KEY (`term_id`)
			)'
		);

		foreach (
			array(
				"SELECT term_id FROM public.wptests_terms WHERE public.wptests_terms.name = 'BURRITO'",
				"SELECT term_id FROM public.wptests_terms WHERE public.wptests_terms.name LIKE '%Bur%'",
				"SELECT term_id FROM public.wptests_terms WHERE public.wptests_terms.name IN ('BURRITO')",
			) as $query
		) {
			$sql = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $query );

			if ( null !== $sql ) {
				$this->assertSame( $query, $sql );
			}
			$this->assertStringNotContainsString( 'public. LOWER(', (string) $sql );
		}
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
	 * Tests grouped SELECT DISTINCT term ID queries hide ORDER BY expressions.
	 */
	public function test_grouped_distinct_term_id_order_by_name_preserves_visible_projection(): void {
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
			GROUP BY t.term_id
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
					'sql'    => 'SELECT "__wp_pg_distinct"."term_id" AS "term_id" FROM (SELECT DISTINCT t.term_id AS "term_id", MIN(t.name) AS "__wp_pg_order_0" FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN (\'category\') AND tr.object_id IN (1) GROUP BY t.term_id) AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 10',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests grouped SELECT DISTINCT term query rows hide ORDER BY expressions.
	 */
	public function test_grouped_distinct_term_query_order_by_name_preserves_visible_projection(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL, parent INTEGER NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Beta')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Alpha')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent) VALUES (10, 1, 'wptests_tax', 'Beta description', 0)" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent) VALUES (20, 2, 'wptests_tax', 'Alpha description', 0)" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent) VALUES (30, 1, 'other_tax', 'Other description', 0)" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (100, 'post', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (101, 'post', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (102, 'post', 'draft')" );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (100, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (101, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (102, 10)' );
		$driver->query( 'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES (100, 20)' );

		$select = "SELECT DISTINCT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, COUNT(p.post_type) AS count
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN wptests_posts AS p ON p.ID = r.object_id
			WHERE tt.taxonomy IN ('wptests_tax') AND (p.post_type = 'post' OR p.post_type IS NULL) AND (p.post_status = 'publish')
			GROUP BY t.term_id ORDER BY t.name ASC";
		$rows   = $driver->query( $select );

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'term_id', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'count' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame( '2', $rows[0]->term_id );
		$this->assertSame( '20', $rows[0]->term_taxonomy_id );
		$this->assertSame( '1', $rows[0]->count );
		$this->assertSame( '1', $rows[1]->term_id );
		$this->assertSame( '10', $rows[1]->term_taxonomy_id );
		$this->assertSame( '2', $rows[1]->count );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "__wp_pg_distinct"."term_id" AS "term_id", "__wp_pg_distinct"."term_taxonomy_id" AS "term_taxonomy_id", "__wp_pg_distinct"."taxonomy" AS "taxonomy", "__wp_pg_distinct"."description" AS "description", "__wp_pg_distinct"."parent" AS "parent", "__wp_pg_distinct"."count" AS "count" FROM (SELECT DISTINCT t.term_id AS "term_id", tt.term_taxonomy_id AS "term_taxonomy_id", tt.taxonomy AS "taxonomy", tt.description AS "description", tt.parent AS "parent", COUNT (p.post_type) AS "count", MIN(t.name) AS "__wp_pg_order_0" FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN wptests_posts AS p ON p."ID" = r.object_id WHERE tt.taxonomy IN (\'wptests_tax\') AND (p.post_type = \'post\' OR p.post_type IS NULL) AND (p.post_status = \'publish\') GROUP BY t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent) AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests WordPress term cache priming preserves MySQL shared-term row order.
	 */
	public function test_wordpress_term_cache_priming_orders_shared_terms_by_term_taxonomy_id(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (1, 'Shared')" );
		$driver->query( "INSERT INTO wptests_terms (term_id, name) VALUES (2, 'Single')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (20, 1, 'second_tax')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (10, 1, 'first_tax')" );
		$driver->query( "INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES (30, 2, 'single_tax')" );

		$rows = $driver->query(
			'SELECT t.*, tt.* FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id WHERE t.term_id IN (1,2)'
		);

		$this->assertSame(
			array( '10', '20', '30' ),
			array_map(
				static function ( $row ): string {
					return $row->term_taxonomy_id;
				},
				$rows
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT t.*, tt.* FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id WHERE t.term_id IN (1, 2) ORDER BY tt.term_taxonomy_id ASC',
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
					'sql'    => 'SELECT wptests_posts."ID", COUNT(*) OVER() AS "__wp_pg_found_rows" FROM wptests_posts WHERE 1 = 1 AND ((wptests_posts.post_type = \'post\' AND (wptests_posts.post_status = \'publish\'))) ORDER BY wptests_posts.post_date DESC, wptests_posts."ID" DESC LIMIT 1 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '2', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests leading-comment SQL_CALC_FOUND_ROWS SELECTs still use FOUND_ROWS accounting.
	 */
	public function test_leading_comment_sql_calc_found_rows_select_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (1, 'post', 'publish', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (2, 'post', 'publish', '2024-01-01 00:00:00')" );

		$select = "/* cache gate */ SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
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
					'sql'    => 'SELECT wptests_posts."ID", COUNT(*) OVER() AS "__wp_pg_found_rows" FROM wptests_posts WHERE 1 = 1 AND ((wptests_posts.post_type = \'post\' AND (wptests_posts.post_status = \'publish\'))) ORDER BY wptests_posts.post_date DESC, wptests_posts."ID" DESC LIMIT 1 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '2', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests FOUND_ROWS returns the last SQL_CALC_FOUND_ROWS total count.
	 */
	public function test_found_rows_returns_last_sql_calc_found_rows_count(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (1, 'post', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (2, 'post', 'publish')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status) VALUES (3, 'post', 'publish')" );

		$page_rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post'
			ORDER BY wptests_posts.ID ASC
			LIMIT 1, 1"
		);
		$rows      = $driver->query( 'SELECT FOUND_ROWS()' );

		$this->assertCount( 1, $page_rows );
		$this->assertSame( '2', $page_rows[0]->ID );
		$this->assertSame( '3', $rows[0]->{'FOUND_ROWS()'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests non-SQL_CALC SELECT queries do not run FOUND_ROWS accounting.
	 */
	public function test_non_sql_calc_select_does_not_run_found_rows_accounting(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type) VALUES (1, 'post')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type) VALUES (2, 'post')" );

		$rows = $driver->query( 'SELECT ID FROM wptests_posts ORDER BY ID ASC LIMIT 0, 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertSame(
			array(
				array(
					'sql'    => 'SELECT "ID" FROM wptests_posts ORDER BY "ID" ASC LIMIT 1 OFFSET 0',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '0', $found_rows[0]->{'FOUND_ROWS()'} );
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
			LIMIT 0, 1";
		$rows   = $driver->query( $select );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( array( 'ID' ), array_keys( get_object_vars( $rows[0] ) ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertStringNotContainsString( 'SQL_CALC_FOUND_ROWS', $queries[0]['sql'] );
		$this->assertSame(
			'SELECT "__wp_pg_distinct"."ID" AS "ID" FROM (SELECT wptests_users."ID" AS "ID", MIN(user_login) AS "__wp_pg_order_0" FROM wptests_users INNER JOIN wptests_usermeta ON (wptests_users."ID" = wptests_usermeta.user_id) WHERE 1 = 1 AND wptests_usermeta.meta_key = \'foo\' GROUP BY wptests_users."ID") AS "__wp_pg_distinct" ORDER BY "__wp_pg_distinct"."__wp_pg_order_0" ASC LIMIT 1 OFFSET 0',
			$queries[0]['sql']
		);
		$this->assertSame(
			'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM (SELECT DISTINCT wptests_users."ID" FROM wptests_users INNER JOIN wptests_usermeta ON (wptests_users."ID" = wptests_usermeta.user_id) WHERE 1 = 1 AND wptests_usermeta.meta_key = \'foo\') AS "__wp_pg_found_rows"',
			$queries[1]['sql']
		);

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '2', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests simple SQL_CALC_FOUND_ROWS counts use the paged result when possible.
	 */
	public function test_simple_sql_calc_found_rows_count_uses_window_count_for_non_empty_pages(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_postmeta (post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (1, \'post\', \'publish\', \'2024-01-01 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (2, \'post\', \'publish\', \'2024-01-02 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (3, \'post\', \'draft\', \'2024-01-03 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'blue\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'green\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (2, \'color\', \'red\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (3, \'color\', \'red\')' );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE wptests_posts.post_status = 'publish'
				AND wptests_postmeta.meta_key = 'color'
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 1"
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->ID );
		$this->assertSame( array( 'ID' ), array_keys( get_object_vars( $rows[0] ) ) );
		$this->assertSame( array( 'ID' ), array_column( $driver->get_last_column_meta(), 'name' ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'COUNT(*) OVER() AS "__wp_pg_found_rows"', $queries[0]['sql'] );
		$this->assertStringContainsString( 'ORDER BY wptests_posts.post_date DESC', $queries[0]['sql'] );
		$this->assertStringContainsString( 'LIMIT 1 OFFSET 0', $queries[0]['sql'] );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '3', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests grouped associative SQL_CALC_FOUND_ROWS fetches use the count fallback.
	 */
	public function test_sql_calc_found_rows_fetch_group_assoc_uses_count_fallback_without_hidden_column(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT NOT NULL)' );
		$driver->query( "INSERT INTO t (id, v) VALUES (1, 'a')" );
		$driver->query( "INSERT INTO t (id, v) VALUES (2, 'b')" );
		$driver->query( "INSERT INTO t (id, v) VALUES (3, 'c')" );

		$rows = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS id, v FROM t ORDER BY id ASC LIMIT 0, 2',
			PDO::FETCH_GROUP | PDO::FETCH_ASSOC
		);

		$this->assertSame( array( 1, 2 ), array_keys( $rows ) );
		$this->assertSame( array( array( 'v' => 'a' ) ), $rows[1] );
		$this->assertSame( array( array( 'v' => 'b' ) ), $rows[2] );
		$this->assertArrayNotHasKey( '__wp_pg_found_rows', $rows[1][0] );
		$this->assertArrayNotHasKey( '__wp_pg_found_rows', $rows[2][0] );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertStringNotContainsString( 'COUNT(*) OVER() AS "__wp_pg_found_rows"', $queries[0]['sql'] );
		$this->assertSame( 'SELECT id, v FROM t ORDER BY id ASC LIMIT 2 OFFSET 0', $queries[0]['sql'] );
		$this->assertSame( 'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM t', $queries[1]['sql'] );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '3', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests grouped object SQL_CALC_FOUND_ROWS fetches use the count fallback.
	 */
	public function test_sql_calc_found_rows_fetch_group_obj_uses_count_fallback_without_hidden_column(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT NOT NULL)' );
		$driver->query( "INSERT INTO t (id, v) VALUES (1, 'a')" );
		$driver->query( "INSERT INTO t (id, v) VALUES (2, 'b')" );
		$driver->query( "INSERT INTO t (id, v) VALUES (3, 'c')" );

		$rows = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS id, v FROM t ORDER BY id ASC LIMIT 0, 2',
			PDO::FETCH_GROUP | PDO::FETCH_OBJ
		);

		$this->assertSame( array( 1, 2 ), array_keys( $rows ) );
		$this->assertCount( 1, $rows[1] );
		$this->assertCount( 1, $rows[2] );
		$this->assertSame( array( 'v' ), array_keys( get_object_vars( $rows[1][0] ) ) );
		$this->assertSame( 'a', $rows[1][0]->v );
		$this->assertSame( array( 'v' ), array_keys( get_object_vars( $rows[2][0] ) ) );
		$this->assertSame( 'b', $rows[2][0]->v );
		$this->assertFalse( property_exists( $rows[1][0], '__wp_pg_found_rows' ) );
		$this->assertFalse( property_exists( $rows[2][0], '__wp_pg_found_rows' ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertStringNotContainsString( 'COUNT(*) OVER() AS "__wp_pg_found_rows"', $queries[0]['sql'] );
		$this->assertSame( 'SELECT id, v FROM t ORDER BY id ASC LIMIT 2 OFFSET 0', $queries[0]['sql'] );
		$this->assertSame( 'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM t', $queries[1]['sql'] );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '3', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests empty SQL_CALC_FOUND_ROWS pages keep the direct count fallback.
	 */
	public function test_simple_sql_calc_found_rows_count_uses_direct_unordered_source_count_for_empty_pages(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_postmeta (post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (1, \'post\', \'publish\', \'2024-01-01 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (2, \'post\', \'publish\', \'2024-01-02 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (3, \'post\', \'draft\', \'2024-01-03 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'blue\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'green\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (2, \'color\', \'red\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (3, \'color\', \'red\')' );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE wptests_posts.post_status = 'publish'
				AND wptests_postmeta.meta_key = 'color'
			ORDER BY wptests_posts.post_date DESC
			LIMIT 10, 1"
		);

		$this->assertCount( 0, $rows );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( 'COUNT(*) OVER() AS "__wp_pg_found_rows"', $queries[0]['sql'] );
		$this->assertSame(
			'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM wptests_posts INNER JOIN wptests_postmeta ON (wptests_posts."ID" = wptests_postmeta.post_id) WHERE wptests_posts.post_status = \'publish\' AND wptests_postmeta.meta_key = \'color\'',
			$queries[1]['sql']
		);
		$this->assertStringNotContainsString( 'ORDER BY', $queries[1]['sql'] );
		$this->assertStringNotContainsString( 'LIMIT', $queries[1]['sql'] );
		$this->assertStringNotContainsString( 'FROM (SELECT', $queries[1]['sql'] );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '3', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests aggregate SQL_CALC_FOUND_ROWS counts keep the cardinality-preserving wrapper.
	 */
	public function test_aggregate_sql_calc_found_rows_count_keeps_cardinality_preserving_wrapper(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER PRIMARY KEY)' );
		$driver->query( 'INSERT INTO t (id) VALUES (1)' );
		$driver->query( 'INSERT INTO t (id) VALUES (2)' );

		$rows = $driver->query( 'SELECT SQL_CALC_FOUND_ROWS COUNT(*) AS c FROM t LIMIT 1, 1' );

		$this->assertCount( 0, $rows );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM (SELECT COUNT (*) AS c FROM t) AS "__wp_pg_found_rows"',
			$queries[1]['sql']
		);
		$this->assertNotSame(
			'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM t',
			$queries[1]['sql']
		);
		$this->assertStringContainsString( 'FROM (SELECT', $queries[1]['sql'] );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '1', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests grouped SQL_CALC_FOUND_ROWS counts keep the cardinality-preserving wrapper.
	 */
	public function test_grouped_sql_calc_found_rows_count_keeps_cardinality_preserving_wrapper(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_postmeta (post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (1, \'post\', \'publish\', \'2024-01-01 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_type, post_status, post_date) VALUES (2, \'post\', \'publish\', \'2024-01-02 00:00:00\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'blue\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (1, \'color\', \'green\')' );
		$driver->query( 'INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES (2, \'color\', \'red\')' );

		$driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE wptests_posts.post_status = 'publish'
				AND wptests_postmeta.meta_key = 'color'
			GROUP BY wptests_posts.ID
			HAVING COUNT(*) >= 1
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 1"
		);

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'SELECT COUNT(*) AS "__wp_pg_found_rows" FROM (SELECT wptests_posts."ID" FROM wptests_posts INNER JOIN wptests_postmeta ON (wptests_posts."ID" = wptests_postmeta.post_id) WHERE wptests_posts.post_status = \'publish\' AND wptests_postmeta.meta_key = \'color\' GROUP BY wptests_posts."ID" HAVING COUNT (*) >= 1) AS "__wp_pg_found_rows"',
			$queries[1]['sql']
		);
		$this->assertStringNotContainsString( 'ORDER BY', $queries[1]['sql'] );
		$this->assertStringNotContainsString( 'LIMIT', $queries[1]['sql'] );
		$this->assertStringContainsString( 'FROM (SELECT', $queries[1]['sql'] );

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
	 * Tests grouped postmeta value-only queries preserve MySQL case-insensitive LIKE behavior.
	 */
	public function test_grouped_postmeta_value_like_without_key_uses_case_insensitive_mysql_collation_metadata(): void {
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
		$driver->query( "INSERT INTO wptests_posts (`ID`, `post_type`, `post_status`, `post_date`) VALUES (3, 'post', 'publish', '2024-01-03 00:00:00')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'city', 'Lorem')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (1, 'address', '123 Lorem St.')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (2, 'city', 'Lorem')" );
		$driver->query( "INSERT INTO wptests_postmeta (`post_id`, `meta_key`, `meta_value`) VALUES (3, 'city', 'Loren')" );

		$rows = $driver->query(
			"SELECT wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND ( ( wptests_postmeta.meta_value LIKE '%lorem%' ) )
				AND wptests_posts.post_type = 'post'
				AND ( ( wptests_posts.post_status = 'publish' ) )
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 5"
		);

		$this->assertSame(
			array( '2', '1' ),
			array_map(
				static function ( $row ): string {
					return $row->ID;
				},
				$rows
			)
		);
		$this->assertStringContainsString(
			"LOWER(wptests_postmeta.meta_value) LIKE LOWER('%lorem%')",
			$driver->get_last_postgresql_queries()[0]['sql']
		);
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
	 * Tests FOUND_ROWS count queries preserve MySQL token adjacency before translation.
	 */
	public function test_found_rows_count_source_preserves_mysql_cast_and_regexp_tokens(): void {
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

		$unsigned_count_sql = $this->translate_driver_query_with_private_method(
			$driver,
			'get_sql_calc_found_rows_count_query',
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND wptests_postmeta.meta_key = 'num_as_longtext'
				AND CAST(wptests_postmeta.meta_value AS UNSIGNED) > '0'
			GROUP BY wptests_posts.ID
			ORDER BY CAST(wptests_postmeta.meta_value AS UNSIGNED) ASC
			LIMIT 0, 10"
		);

		$this->assertStringContainsString(
			$this->get_expected_mysql_integer_cast_sql( 'wptests_postmeta.meta_value' ) . " > '0'",
			$unsigned_count_sql
		);
		$this->assertStringNotContainsString( 'UNSIGNED', $unsigned_count_sql );
		$this->assertStringNotContainsString( 'ORDER BY', $unsigned_count_sql );
		$this->assertStringNotContainsString( 'LIMIT', $unsigned_count_sql );

		$binary_count_sql = $this->translate_driver_query_with_private_method(
			$driver,
			'get_sql_calc_found_rows_count_query',
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
				INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND CAST(wptests_postmeta.meta_key AS BINARY) REGEXP BINARY 'AAA_FOO_.*'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);

		$this->assertStringContainsString( 'CAST(wptests_postmeta.meta_key AS text) ~', $binary_count_sql );
		$this->assertStringNotContainsString( 'BINARY', $binary_count_sql );
		$this->assertStringNotContainsString( 'REGEXP', $binary_count_sql );

		$decimal_like_count_sql = $this->translate_driver_query_with_private_method(
			$driver,
			'get_sql_calc_found_rows_count_query',
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

		$this->assertStringContainsString( 'AS text) LIKE', $decimal_like_count_sql );
		$this->assertStringNotContainsString( 'DECIMAL (10, 2)) LIKE', $decimal_like_count_sql );
	}

	/**
	 * Tests unsupported grouped DISTINCT ORDER BY shapes fail closed.
	 */
	public function test_distinct_grouped_order_by_unsupported_shapes_fail_closed(): void {
		$driver = $this->create_driver();

		$term_query          = 'SELECT DISTINCT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, COUNT(p.post_type) AS count
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN wptests_posts AS p ON p.ID = r.object_id
			WHERE %s
			GROUP BY t.term_id ORDER BY %s';
		$unsupported_queries = array(
			'SELECT DISTINCT t.term_id, COUNT(*) AS term_tt_count FROM wptests_terms AS t GROUP BY t.term_id ORDER BY t.name ASC',
			'SELECT DISTINCT t.term_id FROM wptests_terms AS t GROUP BY t.term_id, t.slug ORDER BY t.name ASC',
			'SELECT DISTINCT t.term_id FROM wptests_terms AS t GROUP BY t.term_id ORDER BY COUNT(*) DESC',
			sprintf(
				$term_query,
				"tt.taxonomy IN ('wptests_tax', 'category') AND (p.post_status = 'publish')",
				't.name ASC'
			),
			sprintf(
				$term_query,
				"(p.post_status = 'publish')",
				't.name ASC'
			),
			"SELECT DISTINCT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count, COUNT(p.post_type) AS count
			FROM wptests_terms AS t INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN wptests_posts AS p ON p.ID = r.object_id
			WHERE tt.taxonomy IN ('wptests_tax') AND (p.post_status = 'publish')
			GROUP BY t.term_id ORDER BY t.name ASC",
			sprintf(
				$term_query,
				"tt.taxonomy IN ('wptests_tax') AND (p.post_status = 'publish')",
				'p.post_date DESC'
			),
		);

		foreach ( $unsupported_queries as $query ) {
			$sql = $this->translate_driver_query_with_private_method(
				$driver,
				'translate_strict_aggregate_grouped_order_by_query',
				$query
			);

			$this->assertNull( $sql );
		}

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
	 * Tests grouped HAVING aliases can extend GROUP BY using safe inner-join equalities.
	 */
	public function test_grouped_having_inner_join_projection_extension_is_translated_for_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			'SELECT tt.term_id, count(*) AS term_tt_count FROM wptests_term_taxonomy tt INNER JOIN wptests_terms t ON t.term_id = tt.term_id GROUP BY t.term_id HAVING term_tt_count > 1'
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'GROUP BY t.term_id, tt.term_id', $sql );
		$this->assertStringContainsString( 'HAVING (count (*)) > 1', $sql );
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
	 * Tests OR-scoped join equalities are not used for GROUP BY extensions.
	 */
	public function test_grouped_having_or_join_equality_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			'SELECT a.id, b.id AS bid, COUNT(*) AS c FROM a LEFT JOIN b ON a.id = b.id OR b.flag = 1 GROUP BY a.id HAVING c > 0'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests nullable-side GROUP BY semantics from outer joins fail closed.
	 */
	public function test_grouped_having_outer_join_nullable_grouping_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			'SELECT tt.term_id, COUNT(*) AS c FROM tt LEFT JOIN t ON t.term_id = tt.term_id GROUP BY t.term_id HAVING c > 1'
		);

		$this->assertNull( $sql );
	}

	/**
	 * Tests nested predicate equalities are not used for GROUP BY extensions.
	 */
	public function test_grouped_having_nested_equality_fails_closed(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_grouped_having_alias_query',
			'SELECT a.id, b.id AS bid, COUNT(*) AS c FROM a, b WHERE (a.id = b.id) GROUP BY a.id HAVING c > 0'
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
	 * Tests WordPress role-count aggregates preserve ARRAY_N row shape.
	 */
	public function test_user_role_count_aggregate_projection_preserves_array_n_shape(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_users ("ID" INTEGER PRIMARY KEY)' );
		$driver->query( 'CREATE TABLE wptests_usermeta (user_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_users ("ID") VALUES (1)' );
		$driver->query( 'INSERT INTO wptests_users ("ID") VALUES (2)' );
		$driver->query( 'INSERT INTO wptests_users ("ID") VALUES (3)' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (1, \'wptests_capabilities\', \'a:1:{s:13:"administrator";b:1;}\')' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (2, \'wptests_capabilities\', \'a:1:{s:6:"editor";b:1;}\')' );
		$driver->query( 'INSERT INTO wptests_usermeta (user_id, meta_key, meta_value) VALUES (3, \'wptests_capabilities\', \'a:0:{}\')' );

		$rows = $driver->query(
			'SELECT COUNT(NULLIF(`meta_value` LIKE \'%\"administrator\"%\', false)),
				COUNT(NULLIF(`meta_value` LIKE \'%\"editor\"%\', false)),
				COUNT(NULLIF(`meta_value` LIKE \'%\"author\"%\', false)),
				COUNT(NULLIF(`meta_value` LIKE \'%\"contributor\"%\', false)),
				COUNT(NULLIF(`meta_value` LIKE \'%\"subscriber\"%\', false)),
				COUNT(NULLIF(`meta_value` = \'a:0:{}\', false)),
				COUNT(*)
			FROM wptests_usermeta
			INNER JOIN wptests_users ON user_id = ID
			WHERE meta_key = \'wptests_capabilities\''
		);

		$this->assertCount( 1, $rows );
		$this->assertSame(
			array( '1', '1', '0', '0', '0', '1', '3' ),
			array_values( get_object_vars( $rows[0] ) )
		);

		$sql = $driver->get_last_postgresql_queries()[0]['sql'];
		$this->assertSame( 7, substr_count( $sql, ' AS "' ) );
		$this->assertStringContainsString( 'COUNT (*) AS "COUNT (*)"', $sql );
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

		$this->install_options_table_with_mysql_metadata( $driver );

		$unsupported_upsert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.org', 'yes')
			ON DUPLICATE KEY UPDATE `option_value` = 'http://example.net'";

		$this->assertNull(
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$unsupported_upsert
			)
		);

		$this->expectException( PDOException::class );

		$driver->query( $unsupported_upsert );
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
	 * Tests SHOW FIELDS returns the same MySQL-shaped rows as SHOW COLUMNS.
	 */
	public function test_show_fields_returns_same_catalog_rows_as_show_columns(): void {
		$columns_driver = $this->create_driver();
		$fields_driver  = $this->create_driver();
		$this->install_information_schema_fixture( $columns_driver );
		$this->install_information_schema_fixture( $fields_driver );

		$columns = $columns_driver->query( 'SHOW COLUMNS FROM `wptests_options`' );
		$fields  = $fields_driver->query( 'SHOW FIELDS FROM `wptests_options`' );

		$this->assertEquals( $columns, $fields );
		$this->assertSame( 'SHOW FIELDS FROM `wptests_options`', $fields_driver->get_last_mysql_query() );
		$this->assertSame( $columns_driver->get_last_column_count(), $fields_driver->get_last_column_count() );
		$this->assertSame( $columns_driver->get_last_column_meta(), $fields_driver->get_last_column_meta() );

		$queries = $fields_driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW FIELDS', strtoupper( $queries[0]['sql'] ) );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW FULL/EXTENDED FIELDS use the SHOW COLUMNS parser.
	 */
	public function test_show_prefixed_fields_use_show_columns_parser(): void {
		$full_driver = $this->create_driver();
		$this->install_information_schema_fixture( $full_driver );

		$full = $full_driver->query( 'SHOW FULL FIELDS FROM `wptests_options`' );

		$this->assertCount( 4, $full );
		$this->assertSame( 9, $full_driver->get_last_column_count() );
		$this->assertSame( 'Collation', $full_driver->get_last_column_meta()[2]['name'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $full[1]->Collation );
		$this->assertStringNotContainsString(
			'SHOW FULL FIELDS',
			strtoupper( $full_driver->get_last_postgresql_queries()[0]['sql'] )
		);

		$extended_driver = $this->create_driver();
		$this->install_information_schema_fixture( $extended_driver );

		$extended = $extended_driver->query( 'SHOW EXTENDED FIELDS FROM `wptests_options`' );

		$this->assertCount( 4, $extended );
		$this->assertSame( 6, $extended_driver->get_last_column_count() );
		$this->assertSame( 'Field', $extended_driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'option_id', $extended[0]->Field );
		$this->assertStringNotContainsString(
			'SHOW EXTENDED FIELDS',
			strtoupper( $extended_driver->get_last_postgresql_queries()[0]['sql'] )
		);
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
	 * Tests SHOW FIELDS accepts MySQL table qualification forms.
	 */
	public function test_show_fields_accepts_table_qualification_forms(): void {
		$cases = array(
			'SHOW FIELDS IN wptests_options'               => array( 'public', 'wptests_options' ),
			'SHOW FIELDS FROM public.wptests_options'      => array( 'public', 'wptests_options' ),
			'SHOW FIELDS FROM wptests_options FROM public' => array( 'public', 'wptests_options' ),
			'SHOW FIELDS IN wptests_options IN public'     => array( 'public', 'wptests_options' ),
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
			$this->assertStringNotContainsString( 'SHOW FIELDS', strtoupper( $queries[0]['sql'] ), $query );
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
	 * Tests SHOW FIELDS LIKE filters catalog rows with bound parameters.
	 */
	public function test_show_fields_like_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW FIELDS FROM wptests_options LIKE 'option_%'" );

		$this->assertCount( 3, $result );
		$this->assertSame( 'option_id', $result[0]->Field );
		$this->assertSame( 'option_name', $result[1]->Field );
		$this->assertSame( 'option_value', $result[2]->Field );
		$this->assertSame( 6, $driver->get_last_column_count() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name LIKE ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW FIELDS', strtoupper( $queries[0]['sql'] ) );
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
	 * Tests MODIFY COLUMN clauses update backend and metadata definitions.
	 */
	public function test_modify_column_updates_backend_and_metadata_definitions(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_actionscheduler_actions (
				scheduled_date_gmt datetime NOT NULL default '2026-01-01 00:00:00',
				last_attempt_gmt datetime NOT NULL default '2026-01-01 00:00:00'
			)"
		);

		$driver->query(
			"ALTER TABLE wptests_actionscheduler_actions
				MODIFY COLUMN scheduled_date_gmt datetime NULL default '0000-00-00 00:00:00',
				MODIFY COLUMN last_attempt_gmt datetime NULL default '0000-00-00 00:00:00'"
		);

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "scheduled_date_gmt" TYPE text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "scheduled_date_gmt" DROP NOT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "scheduled_date_gmt" SET DEFAULT \'0000-00-00 00:00:00\'',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "last_attempt_gmt" TYPE text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "last_attempt_gmt" DROP NOT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_actionscheduler_actions" ALTER COLUMN "last_attempt_gmt" SET DEFAULT \'0000-00-00 00:00:00\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$describe = $driver->query( 'DESC wptests_actionscheduler_actions' );

		$this->assertSame( 'scheduled_date_gmt', $describe[0]->Field );
		$this->assertSame( 'datetime', $describe[0]->Type );
		$this->assertSame( 'YES', $describe[0]->Null );
		$this->assertSame( '0000-00-00 00:00:00', $describe[0]->Default );
		$this->assertSame( 'last_attempt_gmt', $describe[1]->Field );
		$this->assertSame( 'datetime', $describe[1]->Type );
		$this->assertSame( 'YES', $describe[1]->Null );
		$this->assertSame( '0000-00-00 00:00:00', $describe[1]->Default );
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
	 * Tests unsupported SHOW FIELDS clauses do not fall through to the backend.
	 */
	public function test_show_fields_where_clause_does_not_reach_backend(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		try {
			$driver->query( "SHOW FIELDS FROM wptests_options WHERE Field = 'option_name'" );
			$this->fail( 'Expected unsupported SHOW FIELDS WHERE clause to throw.' );
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
	 * Tests SHOW TABLE STATUS returns MySQL-shaped catalog rows.
	 */
	public function test_show_table_status_returns_mysql_shaped_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( 'SHOW TABLE STATUS' );

		$this->assertCount( 2, $tables );
		$this->assertSame( 'wptests_options', $tables[0]->Name );
		$this->assertSame( 'InnoDB', $tables[0]->Engine );
		$this->assertSame( '10', $tables[0]->Version );
		$this->assertSame( 'Dynamic', $tables[0]->Row_format );
		$this->assertSame( '0', $tables[0]->Rows );
		$this->assertSame( '0', $tables[0]->Avg_row_length );
		$this->assertSame( '0', $tables[0]->Data_length );
		$this->assertSame( '0', $tables[0]->Max_data_length );
		$this->assertSame( '0', $tables[0]->Index_length );
		$this->assertSame( '0', $tables[0]->Data_free );
		$this->assertSame( '1', $tables[0]->Auto_increment );
		$this->assertNull( $tables[0]->Create_time );
		$this->assertNull( $tables[0]->Update_time );
		$this->assertNull( $tables[0]->Check_time );
		$this->assertSame( $driver->get_collation(), $tables[0]->Collation );
		$this->assertNull( $tables[0]->Checksum );
		$this->assertSame( '', $tables[0]->Create_options );
		$this->assertSame( '', $tables[0]->Comment );
		$this->assertSame( 'wptests_posts', $tables[1]->Name );
		$this->assertNull( $tables[1]->Auto_increment );
		$this->assertSame(
			$this->get_show_table_status_column_names(),
			array_column( $driver->get_last_column_meta(), 'name' )
		);

		foreach ( $driver->get_last_postgresql_queries() as $query ) {
			$this->assertStringNotContainsString( 'SHOW TABLE STATUS', $query['sql'] );
		}
		$this->assertStringContainsString( 'information_schema.tables', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests SHOW TABLE STATUS accepts current database qualification forms.
	 */
	public function test_show_table_status_accepts_current_database_qualification_forms(): void {
		$cases = array(
			'SHOW TABLE STATUS FROM wptests',
			'SHOW TABLE STATUS IN `wptests`',
		);

		foreach ( $cases as $query ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			$tables = $driver->query( $query );

			$this->assertSame( array( 'wptests_options', 'wptests_posts' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ), $query );
			$this->assertSame( $query, $driver->get_last_mysql_query(), $query );
			foreach ( $driver->get_last_postgresql_queries() as $postgresql_query ) {
				$this->assertStringNotContainsString( 'SHOW TABLE STATUS', $postgresql_query['sql'], $query );
			}
		}
	}

	/**
	 * Tests SHOW TABLE STATUS LIKE filters rows and hides internal metadata tables.
	 */
	public function test_show_table_status_like_filters_and_hides_internal_metadata_tables(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', '__wp_postgresql_mysql_column_metadata', 'BASE TABLE'),
				('public', '__wp_postgresql_mysql_index_metadata', 'BASE TABLE'),
				('public', '__wp_postgresql_mysql_charset_metadata', 'BASE TABLE'),
				('public', 'other_visible', 'BASE TABLE')"
		);

		$tables = $driver->query( "SHOW TABLE STATUS LIKE 'wptests_%'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts' ),
			array_map( array( $this, 'get_show_table_status_row_name' ), $tables )
		);

		$internal_tables = $driver->query( "SHOW TABLE STATUS LIKE '__wp_postgresql_mysql_%'" );

		$this->assertSame( array(), $internal_tables );
	}

	/**
	 * Tests SHOW TABLE STATUS excludes temporary tables and views.
	 */
	public function test_show_table_status_excludes_temporary_tables_and_views(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$driver->get_connection()->get_pdo()->exec( 'CREATE TEMPORARY TABLE wptests_temp (id INTEGER)' );
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_temp', 'LOCAL TEMPORARY')"
		);

		$tables = $driver->query( 'SHOW TABLE STATUS' );
		$names  = array_map( array( $this, 'get_show_table_status_row_name' ), $tables );

		$this->assertSame( array( 'wptests_options', 'wptests_posts' ), $names );
		$this->assertNotContains( 'wptests_view', $names );
		$this->assertNotContains( 'wptests_temp', $names );
	}

	/**
	 * Tests SHOW TABLE STATUS supports the scoped Auto_increment WHERE filters.
	 */
	public function test_show_table_status_where_filters_by_auto_increment(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_show_table_status_auto_increment_fixture( $driver );

		$tables = $driver->query( 'SHOW TABLE STATUS WHERE `Auto_increment` > 3' );

		$this->assertSame( array( 'wptests_posts' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );
		$this->assertSame( '6', $tables[0]->Auto_increment );

		$tables = $driver->query( 'SHOW TABLE STATUS WHERE Auto_increment IS NULL' );

		$this->assertSame( array( 'wptests_plain' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );
		$this->assertNull( $tables[0]->Auto_increment );
	}

	/**
	 * Tests unsupported SHOW TABLE STATUS WHERE clauses fail before backend execution.
	 */
	public function test_unsupported_show_table_status_where_clause_does_not_reach_backend(): void {
		$unsupported_queries = array(
			"SHOW TABLE STATUS WHERE Name = 'wptests_options'",
			'SHOW TABLE STATUS WHERE `Auto_increment` >= 1',
			'SHOW TABLE STATUS FROM other_db',
		);

		foreach ( $unsupported_queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW TABLE STATUS statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW TABLE STATUS statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests SHOW CREATE TABLE returns MySQL-shaped metadata rows.
	 */
	public function test_show_create_table_returns_mysql_shaped_metadata_result(): void {
		$driver = $this->create_driver();
		$this->install_show_create_table_fixture( $driver, 'wptests_show_create' );

		$tables = $driver->query( 'SHOW CREATE TABLE wptests_show_create' );

		$this->assertCount( 1, $tables );
		$this->assertSame( 'wptests_show_create', $tables[0]->Table );
		$this->assertSame( array( 'Table', 'Create Table' ), array_column( $driver->get_last_column_meta(), 'name' ) );

		$create_table = $tables[0]->{'Create Table'};
		$this->assertStringStartsWith( "CREATE TABLE `wptests_show_create` (\n", $create_table );
		$this->assertStringContainsString( '  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', $create_table );
		$this->assertStringContainsString( "  `title` varchar(191) NOT NULL DEFAULT ''", $create_table );
		$this->assertStringContainsString( '  `description` text DEFAULT NULL', $create_table );
		$this->assertStringContainsString( "  `status` varchar(20) NOT NULL DEFAULT 'draft'", $create_table );
		$this->assertStringContainsString( '  PRIMARY KEY (`id`)', $create_table );
		$this->assertStringContainsString( '  UNIQUE KEY `title` (`title`)', $create_table );
		$this->assertStringContainsString( '  KEY `status` (`status`)', $create_table );
		$this->assertStringContainsString( ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $create_table );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_COLUMN_METADATA_TABLE, $queries[0]['sql'] );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_INDEX_METADATA_TABLE, $queries[1]['sql'] );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'SHOW CREATE TABLE', $query['sql'] );
		}

		$assoc = $driver->query( 'SHOW CREATE TABLE wptests_show_create', PDO::FETCH_ASSOC );
		$this->assertSame( 'wptests_show_create', $assoc[0]['Table'] );
		$this->assertSame( $create_table, $assoc[0]['Create Table'] );

		$num = $driver->query( 'SHOW CREATE TABLE wptests_show_create', PDO::FETCH_NUM );
		$this->assertSame( array( 'wptests_show_create', $create_table ), $num[0] );
	}

	/**
	 * Tests SHOW CREATE TABLE accepts backtick and main database qualifications.
	 */
	public function test_show_create_table_accepts_backtick_and_main_database_qualification_forms(): void {
		$queries = array(
			'SHOW CREATE TABLE `wptests_show_create_forms`',
			'SHOW CREATE TABLE wptests.wptests_show_create_forms',
			'SHOW CREATE TABLE `wptests`.`wptests_show_create_forms`',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$this->install_show_create_table_fixture( $driver, 'wptests_show_create_forms' );

			$tables = $driver->query( $query );

			$this->assertCount( 1, $tables, $query );
			$this->assertSame( 'wptests_show_create_forms', $tables[0]->Table, $query );
			$this->assertStringStartsWith( "CREATE TABLE `wptests_show_create_forms` (\n", $tables[0]->{'Create Table'}, $query );
			foreach ( $driver->get_last_postgresql_queries() as $postgresql_query ) {
				$this->assertStringNotContainsString( 'SHOW CREATE TABLE', $postgresql_query['sql'], $query );
			}
		}
	}

	/**
	 * Tests SHOW CREATE TABLE for a missing table returns an empty metadata result.
	 */
	public function test_show_create_table_missing_table_returns_empty_result(): void {
		$driver = $this->create_driver();

		$tables = $driver->query( 'SHOW CREATE TABLE wptests_missing' );

		$this->assertSame( array(), $tables );
		$this->assertSame( array( 'Table', 'Create Table' ), array_column( $driver->get_last_column_meta(), 'name' ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_COLUMN_METADATA_TABLE, $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW CREATE TABLE', $queries[0]['sql'] );
	}

	/**
	 * Tests SHOW CREATE TABLE information_schema targets fail closed.
	 */
	public function test_show_create_table_information_schema_target_fails_closed(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'SHOW CREATE TABLE information_schema.tables' );
			$this->fail( 'Expected information_schema SHOW CREATE TABLE target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests SHOW GRANTS returns a static MySQL-shaped grants row.
	 */
	public function test_show_grants_returns_static_mysql_shaped_row(): void {
		$driver = $this->create_driver();

		$grants = $driver->query( 'SHOW GRANTS' );

		$this->assertEquals( $this->get_show_grants_expected_result(), $grants );
		$this->assertSame( 'SHOW GRANTS', $driver->get_last_mysql_query() );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 1, $driver->get_last_column_count() );
		$this->assertSame(
			array(
				array(
					'name'             => 'Grants for root@%',
					'table'            => '',
					'mysqli:orgtable'  => '',
					'mysqli:orgname'   => 'Grants for root@%',
					'mysqli:db'        => 'wptests',
					'mysqli:charsetnr' => 45,
					'mysqli:flags'     => 0,
					'mysqli:type'      => 253,
					'len'              => 4096,
					'precision'        => 0,
					'native_type'      => 'string',
				),
			),
			$driver->get_last_column_meta()
		);

		$assoc = $driver->query( 'SHOW GRANTS', PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'Grants for root@%' => $this->get_show_grants_expected_value(),
				),
			),
			$assoc
		);

		$num = $driver->query( 'SHOW GRANTS', PDO::FETCH_NUM );
		$this->assertSame( array( array( $this->get_show_grants_expected_value() ) ), $num );
	}

	/**
	 * Tests supported SHOW GRANTS CURRENT_USER forms return the static grants row.
	 */
	public function test_show_grants_current_user_forms_return_static_row(): void {
		$queries = array(
			'SHOW GRANTS FOR current_user();',
			'SHOW GRANTS FOR CURRENT_USER',
			'sHoW gRaNtS FoR CuRrEnT_UsEr()',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			$grants = $driver->query( $query );

			$this->assertEquals( $this->get_show_grants_expected_result(), $grants, $query );
			$this->assertSame( $query, $driver->get_last_mysql_query(), $query );
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			$this->assertSame( array( 'Grants for root@%' ), array_column( $driver->get_last_column_meta(), 'name' ), $query );
		}
	}

	/**
	 * Tests SHOW GRANTS updates FOUND_ROWS() accounting.
	 */
	public function test_show_grants_sets_found_rows_to_one(): void {
		$driver = $this->create_driver();

		$driver->query( 'SHOW GRANTS' );
		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );

		$this->assertSame( '1', $found_rows[0]->{'FOUND_ROWS()'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests SHOW GRANTS is not table-scoped under USE information_schema.
	 */
	public function test_show_grants_after_use_information_schema_returns_static_row(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$grants = $driver->query( 'SHOW GRANTS' );

		$this->assertEquals( $this->get_show_grants_expected_result(), $grants );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 'information_schema', $driver->get_last_column_meta()[0]['mysqli:db'] );
	}

	/**
	 * Tests unsupported SHOW GRANTS syntax fails before backend execution.
	 */
	public function test_unsupported_show_grants_syntax_fails_closed(): void {
		$queries = array(
			'SHOW GRANTS FOR root',
			'SHOW GRANTS USING role1',
			'SHOW GRANTS FOR CURRENT_USER() USING role1',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW GRANTS statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW GRANTS statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests SHOW COLLATION returns MySQL-shaped static collation rows.
	 */
	public function test_show_collation_returns_mysql_shaped_rows(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SHOW COLLATION' );

		$this->assertSame(
			array(
				'binary',
				'utf8_bin',
				'utf8_general_ci',
				'utf8_unicode_ci',
				'utf8mb4_bin',
				'utf8mb4_unicode_ci',
				'utf8mb4_0900_ai_ci',
			),
			array_map(
				static function ( $row ) {
					return $row->Collation;
				},
				$rows
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame(
			array( 'Collation', 'Charset', 'Id', 'Default', 'Compiled', 'Sortlen', 'Pad_attribute' ),
			array_column( $driver->get_last_column_meta(), 'name' )
		);

		$like_rows = $driver->query( "SHOW COLLATION LIKE 'utf8%'" );
		$this->assertCount( 6, $like_rows );
		$this->assertSame( 'utf8_bin', $like_rows[0]->Collation );
		$this->assertSame( 'utf8mb4_0900_ai_ci', $like_rows[5]->Collation );

		$where_rows = $driver->query( "SHOW COLLATION WHERE Collation = 'utf8_bin'" );
		$this->assertSame( array( 'utf8_bin' ), array( $where_rows[0]->Collation ) );

		try {
			$driver->query( "SHOW COLLATION WHERE 'Collation' = 'utf8_bin'" );
			$this->fail( 'Expected quoted SHOW COLLATION WHERE left operand to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported SHOW COLLATION statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests SHOW DATABASES and SHOW SCHEMAS return MySQL-shaped database rows.
	 */
	public function test_show_databases_and_schemas_return_mysql_shaped_rows(): void {
		$driver = $this->create_driver();

		$databases = $driver->query( 'SHOW DATABASES' );

		$this->assertEquals(
			array(
				(object) array( 'Database' => 'information_schema' ),
				(object) array( 'Database' => 'wptests' ),
			),
			$databases
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array( 'Database' ), array_column( $driver->get_last_column_meta(), 'name' ) );

		$like_rows = $driver->query( 'SHOW DATABASES LIKE "w%"' );
		$this->assertEquals( array( (object) array( 'Database' => 'wptests' ) ), $like_rows );

		$where_rows = $driver->query( 'SHOW DATABASES WHERE `Database` = "information_schema"' );
		$this->assertEquals( array( (object) array( 'Database' => 'information_schema' ) ), $where_rows );

		$where_rows = $driver->query( "SHOW DATABASES WHERE Database = 'information_schema'" );
		$this->assertEquals( array( (object) array( 'Database' => 'information_schema' ) ), $where_rows );

		$unsupported_where_queries = array(
			"SHOW DATABASES WHERE 'Database' = 'information_schema'",
			'SHOW DATABASES WHERE "Database" = \'information_schema\'',
		);
		foreach ( $unsupported_where_queries as $query ) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected quoted SHOW DATABASES WHERE left operand to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW DATABASES statement.', $e->getMessage() );
				$this->assertSame( array(), $driver->get_last_postgresql_queries() );
			}
		}

		$schemas = $driver->query( 'SHOW SCHEMAS' );
		$this->assertEquals( $databases, $schemas );
	}

	/**
	 * Tests USE accepts main database identifiers without backend execution.
	 */
	public function test_use_statement_accepts_main_database_identifiers_without_backend_execution(): void {
		$queries = array(
			'USE wptests',
			'USE `wptests`',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			$this->assertSame( 0, $driver->query( $query ), $query );
			$this->assertSame( $query, $driver->get_last_mysql_query(), $query );
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			$this->assertSame( array(), $driver->get_last_column_meta(), $query );

			$database = $driver->query( 'SELECT DATABASE()' );

			$this->assertSame( 'wptests', $database[0]->{'DATABASE()'}, $query );
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			$this->assertSame( 'DATABASE()', $driver->get_last_column_meta()[0]['name'], $query );
		}
	}

	/**
	 * Tests USE information_schema changes current database state without backend execution.
	 */
	public function test_use_statement_accepts_information_schema_without_backend_execution(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array(), $driver->get_last_column_meta() );

		$database = $driver->query( 'SELECT DATABASE()' );

		$this->assertSame( 'information_schema', $database[0]->{'DATABASE()'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$tables = $driver->query( 'SHOW TABLES' );

		$this->assertCount( 3, $tables );
		$this->assertSame( 'Tables_in_information_schema', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'wptests_options', $tables[0]->Tables_in_information_schema );
		$this->assertStringNotContainsString( 'SHOW TABLES', $driver->get_last_postgresql_queries()[0]['sql'] );

		$databases = $driver->query( 'SHOW DATABASES' );

		$this->assertEquals(
			array(
				(object) array( 'Database' => 'information_schema' ),
				(object) array( 'Database' => 'wptests' ),
			),
			$databases
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests unsupported USE database names fail before backend execution.
	 */
	public function test_use_statement_rejects_unsupported_database_before_backend_execution(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'USE other_db' );
			$this->fail( 'Expected unsupported USE statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported USE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}

		$database = $driver->query( 'SELECT DATABASE()' );

		$this->assertSame( 'wptests', $database[0]->{'DATABASE()'} );
	}

	/**
	 * Tests USE can switch back from information_schema to the main database.
	 */
	public function test_use_statement_switches_back_to_main_database(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );
		$this->assertSame( 0, $driver->query( 'USE `wptests`' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$database = $driver->query( 'SELECT DATABASE()' );

		$this->assertSame( 'wptests', $database[0]->{'DATABASE()'} );

		$tables = $driver->query( 'SHOW TABLES' );

		$this->assertCount( 3, $tables );
		$this->assertSame( 'Tables_in_wptests', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'wptests_options', $tables[0]->Tables_in_wptests );
	}

	/**
	 * Tests information_schema table reads fail closed until routing is implemented.
	 */
	public function test_use_statement_information_schema_table_reads_fail_closed(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE wptests_options (option_id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$queries = array(
			'SELECT * FROM wptests_options',
			'WITH q AS (SELECT * FROM wptests_options) SELECT * FROM q',
			'EXPLAIN SELECT * FROM wptests_options',
		);

		foreach ( $queries as $query ) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected information_schema table read to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported information_schema query.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests information_schema table metadata handlers fail closed until routing is implemented.
	 */
	public function test_use_statement_information_schema_table_metadata_handlers_fail_closed(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		foreach (
			array(
				'SHOW COLUMNS FROM wptests_options',
				'SHOW FIELDS FROM wptests_options',
				'SHOW FULL FIELDS FROM wptests_options',
				'SHOW EXTENDED FIELDS FROM wptests_options',
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected information_schema SHOW COLUMNS/FIELDS to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported information_schema query.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}

		$index_driver = $this->create_show_index_driver();

		$this->assertSame( 0, $index_driver->query( 'USE information_schema' ) );

		foreach (
			array(
				'SHOW INDEX FROM wptests_options',
				'SHOW KEYS FROM wptests_options',
				'SHOW EXTENDED INDEX FROM wptests_options',
				'SHOW EXTENDED INDEXES FROM wptests_options',
				'SHOW EXTENDED KEYS FROM wptests_options',
			) as $query
		) {
			try {
				$index_driver->query( $query );
				$this->fail( 'Expected information_schema SHOW INDEX to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported information_schema query.', $e->getMessage(), $query );
				$this->assertSame( array(), $index_driver->get_last_postgresql_queries(), $query );
			}
		}

		$show_create_driver = $this->create_driver();
		$this->install_show_create_table_fixture( $show_create_driver, 'wptests_options' );

		$this->assertSame( 0, $show_create_driver->query( 'USE information_schema' ) );

		try {
			$show_create_driver->query( 'SHOW CREATE TABLE wptests_options' );
			$this->fail( 'Expected information_schema SHOW CREATE TABLE to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $show_create_driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests information_schema table administration handlers fail closed until routing is implemented.
	 */
	public function test_use_statement_information_schema_table_administration_fails_closed(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE wptests_options (option_id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		try {
			$driver->query( 'CHECK TABLE wptests_options' );
			$this->fail( 'Expected information_schema table administration to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests table administration statements return MySQL-shaped success rows.
	 */
	public function test_table_administration_statements_return_mysql_shaped_success_rows(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_one (id INTEGER)' );
		$driver->query( 'CREATE TABLE administration_two (id INTEGER)' );

		$cases = array(
			'ANALYZE TABLE administration_one'  => 'analyze',
			'CHECK TABLE `administration_one`'  => 'check',
			'OPTIMIZE TABLE administration_one' => 'optimize',
			'REPAIR TABLE administration_one'   => 'repair',
		);

		foreach ( $cases as $query => $operation ) {
			$rows = $driver->query( $query );

			$this->assertEquals(
				array(
					(object) array(
						'Table'    => 'wptests.administration_one',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$rows,
				$query
			);
			$this->assertSame(
				array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
				array_column( $driver->get_last_column_meta(), 'name' ),
				$query
			);
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
		}

		$qualified_rows = $driver->query( 'CHECK TABLE `wptests`.`administration_two`' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wptests.administration_two',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$qualified_rows
		);
	}

	/**
	 * Tests table administration statements treat PostgreSQL temporary tables as existing.
	 */
	public function test_table_administration_statements_treat_postgresql_temporary_table_as_existing(): void {
		$connection = $this->create_table_administration_catalog_fixture_connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$cases = array(
			'ANALYZE TABLE administration_temp'  => 'analyze',
			'CHECK TABLE administration_temp'    => 'check',
			'OPTIMIZE TABLE administration_temp' => 'optimize',
			'REPAIR TABLE administration_temp'   => 'repair',
		);

		foreach ( $cases as $query => $operation ) {
			$rows = $driver->query( $query );

			$this->assertEquals(
				array(
					(object) array(
						'Table'    => 'wptests.administration_temp',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$rows,
				$query
			);
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
		}

		$catalog_queries = $connection->get_table_administration_catalog_queries();
		$this->assertCount( count( $cases ), $catalog_queries );

		foreach ( $catalog_queries as $catalog_query ) {
			$this->assertStringContainsString( 'FROM pg_catalog.pg_class c', $catalog_query['sql'] );
			$this->assertSame( array( 'pg_temp_7', 'administration_temp' ), $catalog_query['params'] );
		}
	}

	/**
	 * Tests table administration statements preserve multiple-table order.
	 */
	public function test_table_administration_multiple_tables_preserve_order(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_first (id INTEGER)' );
		$driver->query( 'CREATE TABLE administration_second (id INTEGER)' );

		$rows = $driver->query( 'CHECK TABLE administration_second, administration_first' );

		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wptests.administration_second',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wptests.administration_first',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$rows
		);
	}

	/**
	 * Tests table administration statements return MySQL-shaped missing-table errors.
	 */
	public function test_table_administration_missing_table_returns_error_and_failed_status(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'OPTIMIZE TABLE administration_missing' );

		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wptests.administration_missing',
					'Op'       => 'optimize',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'administration_missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wptests.administration_missing',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$rows
		);
	}

	/**
	 * Tests table administration statements preserve mixed existing and missing table order.
	 */
	public function test_table_administration_mixed_existing_and_missing_tables_preserve_order(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_existing (id INTEGER)' );

		$rows = $driver->query( 'REPAIR TABLE administration_existing, administration_missing' );

		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wptests.administration_existing',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wptests.administration_missing',
					'Op'       => 'repair',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'administration_missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wptests.administration_missing',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$rows
		);
	}

	/**
	 * Tests unsupported table administration clauses fail before reaching the backend.
	 */
	public function test_table_administration_unsupported_clauses_fail_closed(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_existing (id INTEGER)' );

		try {
			$driver->query( 'CHECK TABLE administration_existing FOR UPGRADE' );
			$this->fail( 'Expected unsupported table administration clause to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported table administration statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests information_schema table administration targets fail closed.
	 */
	public function test_table_administration_information_schema_target_fails_closed(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'CHECK TABLE `information_schema`.`tables`' );
			$this->fail( 'Expected information_schema table administration target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported table administration statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
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
	 * Tests SHOW KEYS returns the same MySQL-shaped rows as SHOW INDEX.
	 */
	public function test_show_keys_returns_same_catalog_rows_as_show_index(): void {
		$index_driver = $this->create_show_index_driver();
		$keys_driver  = $this->create_show_index_driver();

		$indexes = $index_driver->query( 'SHOW INDEX FROM `wptests_options`;' );
		$keys    = $keys_driver->query( 'SHOW KEYS FROM `wptests_options`;' );

		$this->assertEquals( $indexes, $keys );
		$this->assertSame( 'SHOW KEYS FROM `wptests_options`;', $keys_driver->get_last_mysql_query() );
		$this->assertSame( 15, $keys_driver->get_last_column_count() );
		$this->assertSame( 'Table', $keys_driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( 'Key_name', $keys_driver->get_last_column_meta()[2]['name'] );

		$queries = $keys_driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'pg_catalog.pg_index', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW KEYS', strtoupper( $queries[0]['sql'] ) );
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
	 * Tests SHOW KEYS WHERE Key_name uses the SHOW INDEX key-name filter.
	 */
	public function test_show_keys_where_key_name_filters_catalog_rows(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( "SHOW KEYS FROM wptests_options WHERE Key_name = 'autoload'" );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'autoload', $indexes[0]->Key_name );
		$this->assertSame( 'autoload', $indexes[0]->Column_name );
		$this->assertSame( '1', $indexes[0]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Key_name" = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW KEYS', strtoupper( $queries[0]['sql'] ) );
		$this->assertSame( array( 'public', 'wptests_options', 'autoload' ), $queries[0]['params'] );
	}

	/**
	 * Tests unsupported SHOW KEYS clauses fail before reaching the backend.
	 */
	public function test_show_keys_unsupported_syntax_does_not_reach_backend(): void {
		$queries = array(
			'SHOW KEYS IN wptests_options',
			'SHOW KEYS FROM wptests_options WHERE Non_unique = 0',
			'SHOW KEYS FROM wptests_options LIMIT 1',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_show_index_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW KEYS statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW INDEX statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests unsupported SHOW EXTENDED INDEX-family statements fail before reaching the backend.
	 */
	public function test_show_extended_index_family_does_not_reach_backend(): void {
		$queries = array(
			'SHOW EXTENDED INDEX FROM wptests_options',
			'SHOW EXTENDED INDEXES FROM wptests_options',
			'SHOW EXTENDED KEYS FROM wptests_options',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_show_index_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW EXTENDED INDEX statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW INDEX statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests catalog-backed MySQL introspection queries are cached until metadata changes.
	 */
	public function test_mysql_introspection_result_cache_reuses_catalog_rows_until_metadata_changes(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->get_connection()->get_pdo()->exec(
			'CREATE TABLE wptests_options (
				option_id INTEGER,
				option_name TEXT,
				option_value TEXT,
				autoload TEXT
			)'
		);

		$describe_catalog_queries     = 0;
		$show_columns_catalog_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$describe_catalog_queries, &$show_columns_catalog_queries ): void {
				if ( false !== strpos( $sql, 'describe_rows' ) ) {
					++$describe_catalog_queries;
				}
				if ( false !== strpos( $sql, 'show_columns_rows' ) ) {
					++$show_columns_catalog_queries;
				}
			}
		);

		$describe           = $driver->query( 'DESC `wptests_options`;' );
		$describe[0]->Field = 'mutated';

		$cached_describe = $driver->query( 'DESC `wptests_options`;' );

		$this->assertSame( 1, $describe_catalog_queries );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 'option_id', $cached_describe[0]->Field );
		$this->assertSame( 'Field', $driver->get_last_column_meta()[0]['name'] );

		$columns           = $driver->query( 'SHOW COLUMNS FROM `wptests_options`' );
		$columns[0]->Field = 'mutated';

		$cached_columns = $driver->query( 'SHOW COLUMNS FROM `wptests_options`' );

		$this->assertSame( 1, $show_columns_catalog_queries );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 'option_id', $cached_columns[0]->Field );
		$this->assertSame( 'Null', $driver->get_last_column_meta()[2]['name'] );

		$driver->query( 'ALTER TABLE wptests_options ADD KEY option_value (option_value)' );

		$indexed_columns = $driver->query( 'SHOW COLUMNS FROM `wptests_options`' );

		$this->assertSame( 2, $show_columns_catalog_queries );
		$this->assertSame( 'MUL', $indexed_columns[2]->Key );

		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id)
			)"
		);
		$driver->query( 'DESC `wptests_options`;' );

		$this->assertSame( 2, $describe_catalog_queries );

		$index_driver               = $this->create_show_index_driver();
		$show_index_catalog_queries = 0;
		$index_driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$show_index_catalog_queries ): void {
				if ( false !== strpos( $sql, 'show_index_fixture' ) ) {
					++$show_index_catalog_queries;
				}
			}
		);

		$indexes              = $index_driver->query( 'SHOW INDEX FROM `wptests_options`;' );
		$indexes[0]->Key_name = 'mutated';

		$cached_indexes = $index_driver->query( 'SHOW INDEX FROM `wptests_options`;' );

		$this->assertSame( 1, $show_index_catalog_queries );
		$this->assertSame( array(), $index_driver->get_last_postgresql_queries() );
		$this->assertSame( 'PRIMARY', $cached_indexes[0]->Key_name );
		$this->assertSame( 'Key_name', $index_driver->get_last_column_meta()[2]['name'] );
	}

	/**
	 * Tests introspection caching skips FETCH_FUNC closure arguments.
	 */
	public function test_mysql_introspection_result_cache_skips_fetch_func_closure_fetch_mode_args(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$describe_catalog_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$describe_catalog_queries ): void {
				if ( false !== strpos( $sql, 'describe_rows' ) ) {
					++$describe_catalog_queries;
				}
			}
		);

		$fetch_field = static function ( ...$values ) {
			return $values[0];
		};

		$rows        = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );
		$cached_rows = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );

		$this->assertSame( 'option_id', $rows[0] );
		$this->assertSame( 'option_id', $cached_rows[0] );
		$this->assertSame( 2, $describe_catalog_queries );
	}

	/**
	 * Tests introspection caching skips FETCH_FUNC static callback results.
	 */
	public function test_mysql_introspection_result_cache_skips_fetch_func_static_callback_results(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$describe_catalog_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$describe_catalog_queries ): void {
				if ( false !== strpos( $sql, 'describe_rows' ) ) {
					++$describe_catalog_queries;
				}
			}
		);

		self::$mysql_introspection_fetch_func_invocations = 0;
		$fetch_field                                      = array( self::class, 'fetch_dynamic_field_for_introspection_cache_test' );

		$rows        = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );
		$cached_rows = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );

		$this->assertSame(
			array( '1:option_id', '2:option_name', '3:option_value', '4:autoload' ),
			$rows
		);
		$this->assertSame(
			array( '5:option_id', '6:option_name', '7:option_value', '8:autoload' ),
			$cached_rows
		);
		$this->assertSame( 8, self::$mysql_introspection_fetch_func_invocations );
		$this->assertSame( 2, $describe_catalog_queries );
		$this->assertCount( 1, $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests introspection caching skips FETCH_FUNC named function results.
	 */
	public function test_mysql_introspection_result_cache_skips_fetch_func_named_function_results(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$describe_catalog_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$describe_catalog_queries ): void {
				if ( false !== strpos( $sql, 'describe_rows' ) ) {
					++$describe_catalog_queries;
				}
			}
		);

		global $wp_postgresql_driver_named_fetch_func_invocations;
		$wp_postgresql_driver_named_fetch_func_invocations = 0;
		$fetch_field                                       = 'wp_postgresql_driver_fetch_dynamic_field_for_introspection_cache_test';

		$rows        = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );
		$cached_rows = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_FUNC, $fetch_field );

		$this->assertSame(
			array( '1:option_id', '2:option_name', '3:option_value', '4:autoload' ),
			$rows
		);
		$this->assertSame(
			array( '5:option_id', '6:option_name', '7:option_value', '8:autoload' ),
			$cached_rows
		);
		$this->assertSame( 8, $wp_postgresql_driver_named_fetch_func_invocations );
		$this->assertSame( 2, $describe_catalog_queries );
		$this->assertCount( 1, $driver->get_last_postgresql_queries() );
	}

	/**
	 * Fetch a dynamic field value for the FETCH_FUNC introspection cache test.
	 *
	 * @param mixed ...$values Fetched row values.
	 * @return string Dynamic field value.
	 */
	public static function fetch_dynamic_field_for_introspection_cache_test( ...$values ): string {
		++self::$mysql_introspection_fetch_func_invocations;
		return self::$mysql_introspection_fetch_func_invocations . ':' . $values[0];
	}

	/**
	 * Tests introspection caching skips FETCH_CLASS rows without public cloning.
	 */
	public function test_mysql_introspection_result_cache_skips_fetch_class_rows_without_public_clone(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$describe_catalog_queries = 0;
		$driver->get_connection()->set_query_logger(
			static function ( string $sql ) use ( &$describe_catalog_queries ): void {
				if ( false !== strpos( $sql, 'describe_rows' ) ) {
					++$describe_catalog_queries;
				}
			}
		);

		$fetch_class = $this->get_no_public_clone_fetch_row_class_name();
		$rows        = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_CLASS, $fetch_class );
		$cached_rows = $driver->query( 'DESC `wptests_options`;', PDO::FETCH_CLASS, $fetch_class );

		$this->assertInstanceOf( $fetch_class, $rows[0] );
		$this->assertInstanceOf( $fetch_class, $cached_rows[0] );
		$this->assertSame( 'option_id', $rows[0]->Field );
		$this->assertSame( 'option_id', $cached_rows[0]->Field );
		$this->assertSame( 2, $describe_catalog_queries );
	}

	/**
	 * Tests MySQL-only runtime SET statements are handled before reaching PDO.
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
	 * Tests simple MySQL transaction-control statements use direct backend statements.
	 */
	public function test_mysql_transaction_control_statements_use_fast_backend_path(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'START TRANSACTION;' ) );
		$this->assertSame( 'START TRANSACTION;', $driver->get_last_mysql_query() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'BEGIN',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$driver->query( 'CREATE TABLE transaction_test (id INTEGER)' );
		$driver->query( 'INSERT INTO transaction_test (id) VALUES (1)' );

		$this->assertSame( 0, $driver->query( 'ROLLBACK' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ROLLBACK',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$tables = $driver->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'transaction_test'" );
		$this->assertSame( array(), $tables );

		$this->assertSame( 0, $driver->query( 'COMMIT' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests public SAVEPOINT statements return MySQL-compatible results.
	 */
	public function test_mysql_savepoint_statements_use_public_query_path(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES' );

		$this->assertSame( 0, $driver->query( 'START TRANSACTION' ) );
		$driver->query( 'CREATE TABLE savepoint_public (id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'SAVEPOINT s1' ) );
		$this->assertSame( 0, $driver->get_last_column_count() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 'SAVEPOINT "s1"', $this->get_last_single_postgresql_sql( $driver ) );

		$driver->query( 'INSERT INTO savepoint_public VALUES (1)' );
		$driver->query( 'SELECT 1 AS warm_read' );

		$this->assertSame( 0, $driver->query( 'ROLLBACK TO SAVEPOINT s1' ) );
		$this->assertSame( 0, $driver->get_last_column_count() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 'ROLLBACK TO SAVEPOINT "s1"', $this->get_last_single_postgresql_sql( $driver ) );

		$this->assertSame( 0, $driver->query( 'RELEASE SAVEPOINT s1' ) );
		$this->assertSame( 0, $driver->get_last_column_count() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 'RELEASE SAVEPOINT "s1"', $this->get_last_single_postgresql_sql( $driver ) );

		$rows = $driver->query( 'SELECT COUNT(*) AS row_count FROM savepoint_public' );

		$this->assertSame( '0', $rows[0]->row_count );
	}

	/**
	 * Tests unsupported savepoint-family statements fail before raw backend execution.
	 */
	public function test_unsupported_mysql_savepoint_statements_fail_closed_without_backend_execution(): void {
		$cases = array(
			'RELEASE s',
			'ROLLBACK WORK TO SAVEPOINT s',
			'ROLLBACK WORK TO s',
		);

		foreach ( $cases as $query ) {
			$driver = $this->create_driver();
			$driver->query( 'SAVEPOINT s' );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SAVEPOINT statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SAVEPOINT statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests TRUNCATE TABLE removes rows and returns empty result metadata.
	 */
	public function test_mysql_truncate_table_removes_rows_and_returns_empty_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE truncate_test (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$driver->query( "INSERT INTO truncate_test (value) VALUES ('before')" );
		$driver->query( "INSERT INTO truncate_test (value) VALUES ('again')" );

		$this->assertSame( 0, $driver->query( 'TRUNCATE TABLE truncate_test' ) );
		$this->assertSame( 0, $driver->get_last_column_count() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 'DELETE FROM "truncate_test"', $this->get_last_single_postgresql_sql( $driver ) );

		$rows = $driver->query( 'SELECT COUNT(*) AS row_count FROM truncate_test' );
		$this->assertSame( '0', $rows[0]->row_count );

		$driver->query( "INSERT INTO truncate_test (value) VALUES ('after')" );
		$rows = $driver->query( 'SELECT id, value FROM truncate_test' );

		$this->assertEquals(
			array(
				(object) array(
					'id'    => '1',
					'value' => 'after',
				),
			),
			$rows
		);
	}

	/**
	 * Tests main database-qualified table names work for a focused basic operation slice.
	 */
	public function test_main_database_qualified_table_names_work_for_basic_table_operations(): void {
		$driver = $this->create_driver( 'wp' );

		$this->assertSame( 0, $driver->query( 'CREATE TABLE wp.t (id INT PRIMARY KEY)' ) );
		$this->assertStringStartsWith( 'CREATE TABLE "t"', $this->get_last_single_postgresql_sql( $driver ) );

		$this->assertSame( 1, $driver->query( 'INSERT INTO wp.t (id) VALUES (1)' ) );
		$this->assertSame( 'INSERT INTO "t" ("id") VALUES (1)', $this->get_last_single_postgresql_sql( $driver ) );

		$rows = $driver->query( 'SELECT * FROM wp.t' );
		$this->assertEquals( array( (object) array( 'id' => '1' ) ), $rows );
		$this->assertSame( 'SELECT * FROM t', $this->get_last_single_postgresql_sql( $driver ) );

		$driver->query( 'UPDATE wp.t SET id = 2' );
		$rows = $driver->query( 'SELECT * FROM wp.t' );
		$this->assertEquals( array( (object) array( 'id' => '2' ) ), $rows );

		$this->assertSame( 1, $driver->query( 'DELETE FROM wp.t WHERE id = 2' ) );
		$rows = $driver->query( 'SELECT * FROM wp.t' );
		$this->assertSame( array(), $rows );

		$driver->query( 'INSERT INTO wp.t (id) VALUES (3)' );
		$this->assertSame( 0, $driver->query( 'TRUNCATE TABLE wp.t' ) );
		$this->assertSame( 0, $driver->get_last_column_count() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 'DELETE FROM "t"', $this->get_last_single_postgresql_sql( $driver ) );

		$rows = $driver->query( 'SELECT * FROM wp.t' );
		$this->assertSame( array(), $rows );
	}

	/**
	 * Tests malformed main database-qualified CREATE TABLE targets fail closed.
	 */
	public function test_create_table_rejects_extra_qualified_main_database_target(): void {
		$driver = $this->create_driver( 'wp' );

		try {
			$driver->query( 'CREATE TABLE wp.other.t (id INT)' );
			$this->fail( 'Expected extra qualified CREATE TABLE target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported CREATE TABLE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests UNLOCK TABLES forms are MySQL compatibility no-ops.
	 */
	public function test_mysql_unlock_tables_statements_are_noops_without_backend_execution(): void {
		$driver = $this->create_driver();

		foreach ( array( 'UNLOCK TABLES', 'UNLOCK TABLE' ) as $query ) {
			$driver->query( 'SELECT 1 AS previous_value' );

			$this->assertSame( 0, $driver->query( $query ), $query );
			$this->assertSame( $query, $driver->get_last_mysql_query(), $query );
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			$this->assertSame( array(), $driver->get_last_column_meta(), $query );
			$this->assertSame( 0, $driver->get_last_column_count(), $query );
			$this->assertSame( 0, $driver->get_last_return_value(), $query );
		}
	}

	/**
	 * Tests LOCK TABLES forms validate existing tables and then no-op.
	 */
	public function test_mysql_lock_tables_existing_tables_are_noops_without_backend_execution(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE lock_table_one (id INTEGER)' );
		$driver->query( 'CREATE TABLE lock_table_two (id INTEGER)' );
		$driver->query( 'CREATE TABLE lock_table_three (id INTEGER)' );

		$cases = array(
			'LOCK TABLES lock_table_one READ',
			'LOCK TABLES lock_table_one WRITE',
			'LOCK TABLE lock_table_one READ',
			'LOCK TABLE lock_table_one WRITE',
			'LOCK TABLES lock_table_one READ, lock_table_two READ, lock_table_three WRITE',
		);

		foreach ( $cases as $query ) {
			$driver->query( 'SELECT 1 AS previous_value' );

			$this->assertSame( 0, $driver->query( $query ), $query );
			$this->assertSame( $query, $driver->get_last_mysql_query(), $query );
			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			$this->assertSame( array(), $driver->get_last_column_meta(), $query );
			$this->assertSame( 0, $driver->get_last_column_count(), $query );
			$this->assertSame( 0, $driver->get_last_return_value(), $query );
		}
	}

	/**
	 * Tests LOCK TABLES accepts main database-qualified table references.
	 */
	public function test_mysql_lock_tables_accepts_main_database_qualified_table_references(): void {
		$driver = $this->create_driver( 'wp' );
		$driver->query( 'CREATE TABLE lock_qualified_table (id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'LOCK TABLES wp.lock_qualified_table READ' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests LOCK TABLES accepts existing temporary tables.
	 */
	public function test_mysql_lock_tables_accepts_existing_temporary_tables(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TEMPORARY TABLE lock_temp_table (id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'LOCK TABLES lock_temp_table WRITE' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array(), $driver->get_last_column_meta() );
		$this->assertSame( 0, $driver->get_last_column_count() );
	}

	/**
	 * Tests LOCK TABLES missing targets fail before raw backend execution.
	 */
	public function test_mysql_lock_tables_missing_table_fails_before_backend_execution(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE lock_existing_table (id INTEGER)' );

		try {
			$driver->query( 'LOCK TABLES lock_existing_table READ, lock_missing_table WRITE' );
			$this->fail( 'Expected missing LOCK TABLES target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Table 'wptests.lock_missing_table' doesn't exist", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests LOCK TABLES information_schema targets fail closed.
	 */
	public function test_mysql_lock_tables_information_schema_targets_fail_closed(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'LOCK TABLES information_schema.tables READ' );
			$this->fail( 'Expected information_schema LOCK TABLES target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported LOCK TABLES statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests LOCK TABLES under USE information_schema fails closed.
	 */
	public function test_mysql_lock_tables_after_use_information_schema_fails_closed(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE tables (id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		try {
			$driver->query( 'LOCK TABLES tables READ' );
			$this->fail( 'Expected information_schema LOCK TABLES target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests unsupported LOCK TABLES modes fail before raw backend execution.
	 */
	public function test_mysql_lock_tables_unsupported_modes_fail_before_backend_execution(): void {
		$queries = array(
			'LOCK TABLES lock_mode_table LOW_PRIORITY WRITE',
			'LOCK TABLES lock_mode_table READ LOCAL',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$driver->query( 'CREATE TABLE lock_mode_table (id INTEGER)' );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported LOCK TABLES mode to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported LOCK TABLES statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests LOCK/UNLOCK TABLES no-ops do not commit user transactions.
	 */
	public function test_mysql_lock_tables_noop_does_not_commit_user_transaction(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE lock_transaction_table (id INTEGER)' );

		$this->assertSame( 0, $driver->query( 'START TRANSACTION' ) );
		$this->assertSame( 1, $driver->query( 'INSERT INTO lock_transaction_table (id) VALUES (1)' ) );

		$this->assertSame( 0, $driver->query( 'LOCK TABLES lock_transaction_table WRITE' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$this->assertSame( 0, $driver->query( 'UNLOCK TABLES' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$this->assertSame( 0, $driver->query( 'ROLLBACK' ) );

		$rows = $driver->query( 'SELECT COUNT(*) AS lock_row_count FROM lock_transaction_table' );
		$this->assertSame( 0, (int) $rows[0]->lock_row_count );
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
	 * Tests built-in MySQL version variables are selected from emulated state.
	 */
	public function test_select_builtin_version_variables_returns_mysql_compatible_values_without_backend_queries(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SELECT @@version, @@version_comment' );

		$this->assertSame( '8.0.38', $rows[0]->{'@@version'} );
		$this->assertSame( 'MySQL Community Server - GPL', $rows[0]->{'@@version_comment'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( '@@version', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( '@@version_comment', $driver->get_last_column_meta()[1]['name'] );
	}

	/**
	 * Tests public SQL mode changes override earlier SQL SET state consistently.
	 */
	public function test_public_sql_mode_setter_overrides_sql_set_state_for_select_and_show_variables(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" ) );
		$this->assertSame( 'NO_AUTO_VALUE_ON_ZERO', $driver->get_sql_mode() );

		$driver->set_sql_mode( 'STRICT_ALL_TABLES' );

		$this->assertSame( 'STRICT_ALL_TABLES', $driver->get_sql_mode() );

		$rows = $driver->query( 'SELECT @@sql_mode' );
		$this->assertSame( 'STRICT_ALL_TABLES', $rows[0]->{'@@sql_mode'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$rows = $driver->query( "SHOW VARIABLES WHERE Variable_name='sql_mode'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'sql_mode', $rows[0]->Variable_name );
		$this->assertSame( 'STRICT_ALL_TABLES', $rows[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests bare SHOW VARIABLES returns all emulated session variables.
	 */
	public function test_bare_show_variables_returns_all_known_session_variables_without_backend_queries(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( "SET NAMES 'utf8' COLLATE 'utf8_general_ci'" ) );

		$rows = $driver->query( 'SHOW VARIABLES' );

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'character_set_client',
					'Value'         => 'utf8',
				),
				array(
					'Variable_name' => 'character_set_connection',
					'Value'         => 'utf8',
				),
				array(
					'Variable_name' => 'character_set_results',
					'Value'         => 'utf8',
				),
				array(
					'Variable_name' => 'character_set_database',
					'Value'         => 'utf8',
				),
				array(
					'Variable_name' => 'character_set_server',
					'Value'         => 'utf8',
				),
				array(
					'Variable_name' => 'collation_connection',
					'Value'         => 'utf8_general_ci',
				),
				array(
					'Variable_name' => 'collation_database',
					'Value'         => 'utf8_general_ci',
				),
				array(
					'Variable_name' => 'collation_server',
					'Value'         => 'utf8_general_ci',
				),
				array(
					'Variable_name' => 'sql_mode',
					'Value'         => 'NO_ENGINE_SUBSTITUTION',
				),
			),
			array_map(
				static function ( $row ) {
					return array(
						'Variable_name' => $row->Variable_name,
						'Value'         => $row->Value,
					);
				},
				$rows
			)
		);
		$this->assertSame( 'SHOW VARIABLES', $driver->get_last_mysql_query() );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( 2, $driver->get_last_column_count() );
		$this->assertSame(
			array(
				array(
					'name'             => 'Variable_name',
					'table'            => '',
					'mysqli:orgtable'  => '',
					'mysqli:orgname'   => 'Variable_name',
					'mysqli:db'        => 'wptests',
					'mysqli:charsetnr' => 45,
					'mysqli:flags'     => 0,
					'mysqli:type'      => 253,
					'len'              => 64,
					'precision'        => 0,
					'native_type'      => 'string',
				),
				array(
					'name'             => 'Value',
					'table'            => '',
					'mysqli:orgtable'  => '',
					'mysqli:orgname'   => 'Value',
					'mysqli:db'        => 'wptests',
					'mysqli:charsetnr' => 45,
					'mysqli:flags'     => 0,
					'mysqli:type'      => 253,
					'len'              => 1024,
					'precision'        => 0,
					'native_type'      => 'string',
				),
			),
			$driver->get_last_column_meta()
		);
	}

	/**
	 * Tests SHOW GLOBAL/SESSION VARIABLES match bare SHOW VARIABLES.
	 */
	public function test_scoped_show_variables_matches_bare_show_variables(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( "SET NAMES 'utf8' COLLATE 'utf8_general_ci'" ) );

		$bare = $driver->query( 'SHOW VARIABLES' );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$global = $driver->query( 'SHOW GLOBAL VARIABLES' );
		$this->assertEquals( $bare, $global );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$session = $driver->query( 'SHOW SESSION VARIABLES' );
		$this->assertEquals( $bare, $session );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests scoped SHOW VARIABLES LIKE and WHERE filters are emulated.
	 */
	public function test_scoped_show_variables_like_and_where_filters_work(): void {
		$driver = $this->create_driver();

		$global_like = $driver->query( "SHOW GLOBAL VARIABLES LIKE 'character_set_c%'" );
		$this->assertSame(
			array(
				'character_set_client',
				'character_set_connection',
			),
			array_map(
				static function ( $row ) {
					return $row->Variable_name;
				},
				$global_like
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$session_like = $driver->query( "SHOW SESSION VARIABLES LIKE 'collation_%'" );
		$this->assertSame(
			array(
				'collation_connection',
				'collation_database',
				'collation_server',
			),
			array_map(
				static function ( $row ) {
					return $row->Variable_name;
				},
				$session_like
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$global_where = $driver->query( "SHOW GLOBAL VARIABLES WHERE Variable_name = 'character_set_client'" );
		$this->assertCount( 1, $global_where );
		$this->assertSame( 'character_set_client', $global_where[0]->Variable_name );
		$this->assertSame( 'utf8mb4', $global_where[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$session_where = $driver->query( "SHOW SESSION VARIABLES WHERE Variable_name = 'collation_connection'" );
		$this->assertCount( 1, $session_where );
		$this->assertSame( 'collation_connection', $session_where[0]->Variable_name );
		$this->assertSame( 'utf8mb4_unicode_ci', $session_where[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests unsupported SHOW VARIABLES WHERE clauses fail before backend execution.
	 */
	public function test_unsupported_show_variables_where_clause_does_not_reach_backend(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( "SHOW VARIABLES WHERE Value = 'utf8mb4'" );
			$this->fail( 'Expected unsupported SHOW VARIABLES statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported SHOW VARIABLES statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
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
	 * Tests SET CHARSET aliases update MySQL-compatible SHOW VARIABLES output.
	 */
	public function test_set_charset_aliases_update_show_variables_session_state(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'SET CHARSET utf8' ) );

		$charset = $driver->query( "SHOW VARIABLES WHERE Variable_name='character_set_client'" );
		$this->assertCount( 1, $charset );
		$this->assertSame( 'utf8', $charset[0]->Value );

		$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
		$this->assertCount( 1, $collation );
		$this->assertSame( 'utf8_general_ci', $collation[0]->Value );

		$this->assertSame( 0, $driver->query( 'SET CHARACTER SET utf8mb4' ) );

		$charset = $driver->query( "SHOW VARIABLES WHERE Variable_name='character_set_client'" );
		$this->assertCount( 1, $charset );
		$this->assertSame( 'utf8mb4', $charset[0]->Value );

		$collation = $driver->query( "SHOW VARIABLES WHERE Variable_name='collation_connection'" );
		$this->assertCount( 1, $collation );
		$this->assertSame( 'utf8mb4_unicode_ci', $collation[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests supported MySQL session variables can be selected with MySQL aliases.
	 */
	public function test_session_system_variables_can_be_selected_with_mysql_aliases(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( "SET character_set_client = 'latin1'" ) );
		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'latin1', $rows[0]->{'@@character_set_client'} );

		$this->assertSame( 0, $driver->query( "SET @@character_set_client = 'utf8mb3'" ) );
		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'utf8mb3', $rows[0]->{'@@character_set_client'} );

		$this->assertSame( 0, $driver->query( "SET @@session.character_set_client = 'utf8mb4'" ) );
		$rows = $driver->query( 'SELECT @@session.character_set_client' );
		$this->assertSame( 'utf8mb4', $rows[0]->{'@@session.character_set_client'} );

		$this->assertSame( 0, $driver->query( 'SET default_storage_engine = InnoDB' ) );
		$rows = $driver->query( 'SELECT @@default_storage_engine' );
		$this->assertSame( 'InnoDB', $rows[0]->{'@@default_storage_engine'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests comma-separated boolean SET assignments are applied atomically.
	 */
	public function test_comma_separated_boolean_set_assignments_are_atomic(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'SET autocommit = ON, big_tables = OFF' ) );

		$rows = $driver->query( 'SELECT @@autocommit, @@big_tables' );
		$this->assertSame( '1', $rows[0]->{'@@autocommit'} );
		$this->assertSame( '0', $rows[0]->{'@@big_tables'} );

		try {
			$driver->query( 'SET autocommit = OFF, unsupported_setting = 1' );
			$this->fail( 'Expected unsupported SET statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported SET statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}

		$rows = $driver->query( 'SELECT @@autocommit, @@big_tables' );
		$this->assertSame( '1', $rows[0]->{'@@autocommit'} );
		$this->assertSame( '0', $rows[0]->{'@@big_tables'} );
	}

	/**
	 * Tests user variables can be set, incremented, selected, and used for restore.
	 */
	public function test_user_variables_can_be_set_incremented_selected_and_used_for_restore(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'SET @my_var = 1' ) );
		$rows = $driver->query( 'SELECT @my_var' );
		$this->assertSame( '1', $rows[0]->{'@my_var'} );

		$this->assertSame( 0, $driver->query( 'SET @my_var = @my_var + 1' ) );
		$rows = $driver->query( 'SELECT @my_var' );
		$this->assertSame( '2', $rows[0]->{'@my_var'} );

		$this->assertSame( 0, $driver->query( 'SET @saved_cs_client = @@character_set_client' ) );
		$this->assertSame( 0, $driver->query( 'SET character_set_client = latin1' ) );

		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'latin1', $rows[0]->{'@@character_set_client'} );

		$this->assertSame( 0, $driver->query( 'SET character_set_client = @saved_cs_client' ) );
		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'utf8mb4', $rows[0]->{'@@character_set_client'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests conditional-comment SET wrappers work for supported backup and restore forms.
	 */
	public function test_conditional_comment_set_wrappers_handle_supported_backup_and_restore(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( '/*!50503 SET NAMES utf8 */;' ) );
		$this->assertSame( 0, $driver->query( '/*!40101 SET @saved_cs_client = @@character_set_client */; ' ) );
		$this->assertSame( 0, $driver->query( '/*!50503 SET character_set_client = latin1 */;' ) );

		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'latin1', $rows[0]->{'@@character_set_client'} );

		$this->assertSame( 0, $driver->query( '/*!40101 SET character_set_client = @saved_cs_client */;' ) );
		$rows = $driver->query( 'SELECT @@character_set_client' );
		$this->assertSame( 'utf8', $rows[0]->{'@@character_set_client'} );
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
	 * Tests unsupported SET statements fail before reaching PDO.
	 */
	public function test_unsupported_set_statements_do_not_reach_backend(): void {
		$driver = $this->create_driver();

		foreach (
			array(
				'SET unsupported_setting = 1',
				'SET foreign_key_checks = 0, unsupported_setting = 1',
				'SET autocommit = 1 + 1',
				'SET @my_var = @my_var * 1',
				"SET @@version = '8.0.39'",
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SET statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SET statement.', $e->getMessage() );
				$this->assertSame( $query, $driver->get_last_mysql_query() );
				$this->assertSame( array(), $driver->get_last_postgresql_queries() );
			}
		}
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
	 * Install a PostgreSQL-like posts table with MySQL datetime metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_posts_datetime_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY,
				post_date TEXT NOT NULL,
				post_date_gmt TEXT NOT NULL,
				post_modified TEXT NOT NULL,
				post_modified_gmt TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_modified_gmt timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (ID)
			)"
		);
	}

	/**
	 * Install a term relationships table with MySQL composite key metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $table_name Table name.
	 */
	private function install_term_relationships_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver, string $table_name ): void {
		$driver->query(
			sprintf(
				'CREATE TABLE `%s` (
					`object_id` bigint(20) unsigned NOT NULL DEFAULT 0,
					`term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT 0,
					`term_order` int(11) NOT NULL DEFAULT 0,
					PRIMARY KEY (`object_id`, `term_taxonomy_id`)
				)',
				$table_name
			)
		);
	}

	/**
	 * Install an upsert table with ambiguous MySQL duplicate-key metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_ambiguous_upsert_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE ambiguous_upsert (
				id INTEGER PRIMARY KEY,
				slug TEXT NOT NULL UNIQUE,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE ambiguous_upsert (
				id bigint(20) unsigned NOT NULL,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			)'
		);
	}

	/**
	 * Install an upsert table with a MySQL prefix unique key.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_prefix_ambiguous_upsert_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE prefix_ambiguous (
				id INTEGER PRIMARY KEY,
				slug TEXT NOT NULL,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE prefix_ambiguous (
				id bigint(20) unsigned NOT NULL,
				slug varchar(255) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug(10))
			)'
		);
	}

	/**
	 * Install an identity table with MySQL auto_increment metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_identity_upsert_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_identity_upsert (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_identity_upsert (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				value longtext NOT NULL,
				PRIMARY KEY (id)
			)'
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
	 * Creates a PostgreSQL driver with tables used by index hint translation tests.
	 *
	 * @return WP_PostgreSQL_Driver Driver under test.
	 */
	private function create_driver_with_index_hint_tables(): WP_PostgreSQL_Driver {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE t (id INTEGER, value TEXT)' );
		$driver->query( 'CREATE TABLE j (t_id INTEGER, value TEXT)' );

		return $driver;
	}

	/**
	 * Get the last single PostgreSQL SQL statement executed by a driver.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 * @return string Last PostgreSQL SQL.
	 */
	private function get_last_single_postgresql_sql( WP_PostgreSQL_Driver $driver ): string {
		$queries = $driver->get_last_postgresql_queries();

		$this->assertCount( 1, $queries );
		return $queries[0]['sql'];
	}

	/**
	 * Assert a PostgreSQL SQL string does not contain raw MySQL index hints.
	 *
	 * @param string $sql PostgreSQL SQL.
	 */
	private function assert_postgresql_sql_omits_mysql_index_hints( string $sql ): void {
		$uppercase_sql = strtoupper( $sql );
		foreach ( array( 'USE INDEX', 'USE KEY', 'FORCE INDEX', 'FORCE KEY', 'IGNORE INDEX', 'IGNORE KEY' ) as $hint ) {
			$this->assertStringNotContainsString( $hint, $uppercase_sql );
		}
	}

	/**
	 * Creates a SQLite-backed driver whose connection reports a stale insert ID.
	 *
	 * @return WP_PostgreSQL_Driver Driver under test.
	 */
	private function create_driver_with_stale_connection_insert_id(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection_Stale_Insert_ID_SQLite_Connection();
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Creates a SQLite-backed driver that uses PostgreSQL quote translation.
	 *
	 * @return WP_PostgreSQL_Driver Driver under test.
	 */
	private function create_driver_with_postgresql_quote_translation(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection_Pgsql_Quote_SQLite_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Creates a connection fixture that exercises production PostgreSQL table administration catalogs.
	 *
	 * @return WP_PostgreSQL_Connection Connection fixture.
	 */
	private function create_table_administration_catalog_fixture_connection(): WP_PostgreSQL_Connection {
		$pdo = new PDO( 'sqlite::memory:' );
		return new class( array( 'pdo' => $pdo ) ) extends WP_PostgreSQL_Connection {
			/**
			 * PostgreSQL catalog existence queries issued by the driver.
			 *
			 * @var array[]
			 */
			private $table_administration_catalog_queries = array();

			/**
			 * Constructor.
			 *
			 * @param array $options Connection options.
			 */
			public function __construct( array $options ) {
				parent::__construct( $options );

				$pdo = $this->get_pdo();
				$pdo->exec(
					'CREATE TABLE table_administration_catalog_fixture (
						table_schema TEXT NOT NULL,
						table_name TEXT NOT NULL,
						relkind TEXT NOT NULL
					)'
				);
				$pdo->exec(
					"INSERT INTO table_administration_catalog_fixture
						(table_schema, table_name, relkind)
					VALUES ('pg_temp_7', 'administration_temp', 'r')"
				);
				$pdo->exec( "ATTACH DATABASE ':memory:' AS information_schema" );
				$pdo->exec(
					'CREATE TABLE information_schema.tables (
						table_schema TEXT NOT NULL,
						table_name TEXT NOT NULL,
						table_type TEXT NOT NULL
					)'
				);
				$pdo->exec(
					"INSERT INTO information_schema.tables
						(table_schema, table_name, table_type)
					VALUES ('pg_temp_7', 'administration_temp', 'LOCAL TEMPORARY')"
				);
			}

			/**
			 * Execute fixture-backed PostgreSQL catalog queries.
			 *
			 * @param string $sql    SQL query.
			 * @param array  $params Query parameters.
			 * @return PDOStatement Statement.
			 */
			public function query( string $sql, array $params = array() ): PDOStatement {
				if (
					false !== strpos( $sql, 'FROM pg_catalog.pg_class c' )
					&& false !== strpos( $sql, 'pg_my_temp_schema()' )
				) {
					return parent::query(
						'SELECT table_schema AS nspname
						FROM table_administration_catalog_fixture
						WHERE table_schema = \'pg_temp_7\'
							AND lower(table_name) = lower(?)
							AND relkind IN (\'r\', \'p\')
						LIMIT 1',
						array( $params[0] ?? '' )
					);
				}

				if ( false !== strpos( $sql, 'FROM pg_catalog.pg_class c' ) ) {
					$this->table_administration_catalog_queries[] = array(
						'sql'    => $sql,
						'params' => $params,
					);

					return parent::query(
						'SELECT 1
						FROM table_administration_catalog_fixture
						WHERE table_schema = ?
							AND table_name = ?
							AND relkind IN (\'r\', \'p\')
						LIMIT 1',
						$params
					);
				}

				return parent::query( $sql, $params );
			}

			/**
			 * Get captured table administration catalog queries.
			 *
			 * @return array[] Catalog queries.
			 */
			public function get_table_administration_catalog_queries(): array {
				return $this->table_administration_catalog_queries;
			}

			/**
			 * Report PostgreSQL for branch selection while keeping SQLite execution available.
			 *
			 * @return string Driver name.
			 */
			public function get_driver_name(): string {
				return 'pgsql';
			}
		};
	}

	/**
	 * Quote a MySQL string literal for parser-facing tests.
	 *
	 * @param string $value Literal value.
	 * @return string MySQL string literal.
	 */
	private function quote_mysql_string_literal_for_test( string $value ): string {
		$backslash = chr( 92 );

		return "'" . strtr(
			$value,
			array(
				$backslash => $backslash . $backslash,
				"'"        => $backslash . "'",
				'"'        => $backslash . '"',
				"\0"       => $backslash . '0',
			)
		) . "'";
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
	 * Translate a query to structured query data by calling a private method.
	 *
	 * @param WP_PostgreSQL_Driver $driver      Driver under test.
	 * @param string               $method_name Private driver method name.
	 * @param string               $query       MySQL query.
	 * @return array|null PostgreSQL query data, or null when unsupported.
	 */
	private function translate_driver_query_data_with_private_method( WP_PostgreSQL_Driver $driver, string $method_name, string $query ): ?array {
		$translator = Closure::bind(
			function ( string $bound_method_name, string $bound_query ): ?array {
				return $this->$bound_method_name( $bound_query );
			},
			$driver,
			WP_PostgreSQL_Driver::class
		);

		return $translator( $method_name, $query );
	}

	/**
	 * Get a private driver property for cache-focused assertions.
	 *
	 * @param WP_PostgreSQL_Driver $driver        Driver under test.
	 * @param string               $property_name Private property name.
	 * @return mixed Private property value.
	 */
	private function get_driver_private_property( WP_PostgreSQL_Driver $driver, string $property_name ) {
		$property_reader = Closure::bind(
			function ( string $bound_property_name ) {
				return $this->$bound_property_name;
			},
			$driver,
			WP_PostgreSQL_Driver::class
		);

		return $property_reader( $property_name );
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
	 * Get the expected SHOW GRANTS result rows.
	 *
	 * @return object[] Expected result rows.
	 */
	private function get_show_grants_expected_result(): array {
		return array(
			(object) array(
				'Grants for root@%' => $this->get_show_grants_expected_value(),
			),
		);
	}

	/**
	 * Get the expected static SHOW GRANTS row value.
	 *
	 * @return string Expected grant text.
	 */
	private function get_show_grants_expected_value(): string {
		return 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, RELOAD, SHUTDOWN, ' .
			'PROCESS, FILE, REFERENCES, INDEX, ALTER, SHOW DATABASES, SUPER, CREATE TEMPORARY TABLES, LOCK TABLES, ' .
			'EXECUTE, REPLICATION SLAVE, REPLICATION CLIENT, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, ' .
			'CREATE USER, EVENT, TRIGGER, CREATE TABLESPACE, CREATE ROLE, DROP ROLE ON *.* TO `root`@`localhost` WITH GRANT OPTION';
	}

	/**
	 * Get a fetch row class name whose clone operation is not publicly callable.
	 *
	 * @return string Fetch row class name.
	 */
	private function get_no_public_clone_fetch_row_class_name(): string {
		$class_name = 'WP_PostgreSQL_Driver_No_Public_Clone_Fetch_Row';
		if ( class_exists( $class_name, false ) ) {
			return $class_name;
		}

		$prototype = new class() {
			/**
			 * Fetched values keyed by column name.
			 *
			 * @var array<string, mixed>
			 */
			private $values = array();

			/**
			 * Store a fetched column value.
			 *
			 * @param string $name  Column name.
			 * @param mixed  $value Column value.
			 */
			public function __set( string $name, $value ): void {
				$this->values[ $name ] = $value;
			}

			/**
			 * Get a fetched column value.
			 *
			 * @param string $name Column name.
			 * @return mixed Column value.
			 */
			public function __get( string $name ) {
				return $this->values[ $name ] ?? null;
			}

			/**
			 * Prevent public cloning.
			 */
			private function __clone() {}
		};

		class_alias( get_class( $prototype ), $class_name );

		return $class_name;
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
	 * Get the SHOW TABLE STATUS result column names.
	 *
	 * @return string[] Column names.
	 */
	private function get_show_table_status_column_names(): array {
		return array(
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
		);
	}

	/**
	 * Get the Name value from a SHOW TABLE STATUS row.
	 *
	 * @param object $row SHOW TABLE STATUS row.
	 * @return string Table name.
	 */
	private function get_show_table_status_row_name( $row ): string {
		return $row->Name;
	}

	/**
	 * Install SHOW TABLE STATUS AUTO_INCREMENT fixture rows.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_show_table_status_auto_increment_fixture( WP_PostgreSQL_Driver $driver ): void {
		$pdo = $driver->get_connection()->get_pdo();

		$pdo->exec( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT)' );
		$pdo->exec(
			"INSERT INTO wptests_posts (value)
			VALUES ('a'), ('b'), ('c'), ('d'), ('e')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_plain', 'BASE TABLE')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.columns
				(table_schema, table_name, column_name, ordinal_position, data_type, character_maximum_length, collation_name, is_nullable, column_default, is_identity)
			VALUES
				('public', 'wptests_posts', 'ID', 1, 'bigint', NULL, NULL, 'NO', NULL, 'YES'),
				('public', 'wptests_plain', 'id', 1, 'bigint', NULL, NULL, 'NO', NULL, 'NO')"
		);
	}

	/**
	 * Install a table shape covered by SHOW CREATE TABLE reconstruction tests.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $table_name Table name.
	 */
	private function install_show_create_table_fixture( WP_PostgreSQL_Driver $driver, string $table_name ): void {
		$driver->query(
			sprintf(
				"CREATE TABLE %s (
					id bigint(20) unsigned NOT NULL,
					title varchar(191) NOT NULL DEFAULT '',
					description text,
					status varchar(20) NOT NULL DEFAULT 'draft',
					PRIMARY KEY (id),
					UNIQUE KEY title (title),
					KEY status (status)
				)",
				$table_name
			)
		);
		$driver->store_mysql_schema_metadata(
			sprintf(
				"CREATE TABLE %s (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					title varchar(191) NOT NULL DEFAULT '',
					description text,
					status varchar(20) NOT NULL DEFAULT 'draft',
					PRIMARY KEY (id),
					UNIQUE KEY title (title),
					KEY status (status)
				)",
				$table_name
			)
		);
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
 * Fetch a dynamic field value for the named-function FETCH_FUNC cache test.
 *
 * @param mixed ...$values Fetched row values.
 * @return string Dynamic field value.
 */
function wp_postgresql_driver_fetch_dynamic_field_for_introspection_cache_test( ...$values ): string {
	global $wp_postgresql_driver_named_fetch_func_invocations;

	++$wp_postgresql_driver_named_fetch_func_invocations;
	return $wp_postgresql_driver_named_fetch_func_invocations . ':' . $values[0];
}
