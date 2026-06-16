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
	 * Tests simple MySQL INSERT forms without INTO and with multi-row VALUES are translated.
	 */
	public function test_simple_insert_without_into_and_multi_row_values_translate_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_bulk_insert (id INTEGER PRIMARY KEY, value TEXT NOT NULL)' );

		$this->assertSame(
			2,
			$driver->query( "INSERT wptests_bulk_insert (`id`, `value`) VALUES (1, 'one'), (2, 'two')" )
		);
		$this->assertSame(
			'INSERT INTO "wptests_bulk_insert" ("id", "value") VALUES (1, \'one\'), (2, \'two\')',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$this->assertSame(
			1,
			$driver->query( "INSERT IGNORE wptests_bulk_insert (`id`, `value`) VALUES (2, 'duplicate'), (3, 'three')" )
		);
		$this->assertSame(
			'INSERT INTO "wptests_bulk_insert" ("id", "value") VALUES (2, \'duplicate\'), (3, \'three\') ON CONFLICT DO NOTHING',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, value FROM wptests_bulk_insert ORDER BY id' );
		$this->assertSame(
			array(
				array( '1', 'one' ),
				array( '2', 'two' ),
				array( '3', 'three' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->value );
				},
				$rows
			)
		);
	}

	/**
	 * Tests simple MySQL INSERT ... SET assignments are translated to PostgreSQL.
	 */
	public function test_simple_insert_set_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_insert_set (id INTEGER PRIMARY KEY, value TEXT NOT NULL, attempts INTEGER NOT NULL)' );

		$insert = "INSERT INTO wptests_insert_set SET id = 1, value = 'one', attempts = 2";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			'INSERT INTO "wptests_insert_set" ("id", "value", "attempts") VALUES (1, \'one\', 2)',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, value, attempts FROM wptests_insert_set' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'one', $rows[0]->value );
		$this->assertSame( '2', $rows[0]->attempts );

		$insert_ignore = "INSERT IGNORE wptests_insert_set SET id = 1, value = 'ignored', attempts = 3";

		$this->assertSame( 0, $driver->query( $insert_ignore ) );
		$this->assertSame(
			'INSERT INTO "wptests_insert_set" ("id", "value", "attempts") VALUES (1, \'ignored\', 3) ON CONFLICT DO NOTHING',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT value, attempts FROM wptests_insert_set WHERE id = 1' );
		$this->assertSame( 'one', $rows[0]->value );
		$this->assertSame( '2', $rows[0]->attempts );
	}

	/**
	 * Tests non-strict INSERT statements append metadata-derived NOT NULL defaults.
	 */
	public function test_non_strict_insert_appends_omitted_not_null_defaults_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( '' );

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
		$driver->set_sql_mode( '' );

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

		$driver->set_sql_mode( 'ANSI_QUOTES' );
		$ansi_tokens = $get_tokens( 'SELECT "ID" FROM "wptests_posts"' );

		$this->assertSame( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID, $ansi_tokens[1]->id );
		$this->assertNotSame( $first_tokens, $ansi_tokens );
	}

	/**
	 * Tests non-strict INSERT normalizes invalid date/time literals using MySQL metadata.
	 */
	public function test_non_strict_insert_normalizes_invalid_date_time_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( '' );
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
	 * Tests strict zero-date SQL modes reject invalid date/time literals before backend execution.
	 */
	public function test_strict_insert_rejects_zero_date_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		try {
			$driver->query( "INSERT INTO `wptests_posts` (`ID`, `post_date`) VALUES (1, '0000-00-00 00:00:00')" );
			$this->fail( 'Expected zero date to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect datetime value: '0000-00-00 00:00:00'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests strict INSERT accepts zero dates when NO_ZERO_DATE is disabled.
	 */
	public function test_strict_insert_accepts_zero_dates_when_no_zero_date_mode_is_disabled(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES' );
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_posts` (`ID`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`)
				VALUES (1, '0000-00-00 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
			)
		);

		$rows = $driver->query( 'SELECT post_date FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '0000-00-00 00:00:00', $rows[0]->post_date );
	}

	/**
	 * Tests non-strict INSERT accepts zero dates when NO_ZERO_DATE is enabled without strict mode.
	 */
	public function test_non_strict_insert_accepts_zero_dates_when_no_zero_date_mode_is_enabled(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'NO_ZERO_DATE' );
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_posts` (`ID`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`)
				VALUES (1, '0000-00-00 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
			)
		);

		$rows = $driver->query( 'SELECT post_date FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '0000-00-00 00:00:00', $rows[0]->post_date );
	}

	/**
	 * Tests strict DATE columns accept zero dates when NO_ZERO_DATE is disabled.
	 */
	public function test_strict_insert_accepts_zero_dates_for_date_columns_when_no_zero_date_mode_is_disabled(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES' );
		$this->install_strict_dml_values_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query( "INSERT INTO `wptests_strict_values` (`id`, `date_value`) VALUES (1, '0000-00-00')" )
		);

		$rows = $driver->query( 'SELECT date_value FROM wptests_strict_values WHERE id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '0000-00-00', $rows[0]->date_value );
	}

	/**
	 * Tests strict zero-date SQL modes reject INSERT ... SELECT date literals before backend execution.
	 */
	public function test_strict_insert_select_rejects_zero_date_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		try {
			$driver->query( "INSERT INTO `wptests_posts` (`ID`, `post_date`) SELECT 1, '0000-00-00 00:00:00' FROM DUAL" );
			$this->fail( 'Expected zero date projection to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect datetime value: '0000-00-00 00:00:00'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests strict zero-in-date SQL modes reject partial-zero date/time literals before backend execution.
	 */
	public function test_strict_update_rejects_zero_in_date_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( '' );
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_date_gmt, post_modified, post_modified_gmt)
			VALUES (1, '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
		);
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE' );

		try {
			$driver->query( "UPDATE `wptests_posts` SET `post_modified` = '2020-00-15 14:15:27' WHERE `ID` = 1" );
			$this->fail( 'Expected zero-in-date to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect datetime value: '2020-00-15 14:15:27'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests strict INSERT accepts partial-zero dates when NO_ZERO_IN_DATE is disabled.
	 */
	public function test_strict_insert_accepts_zero_in_dates_when_no_zero_in_date_mode_is_disabled(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'STRICT_TRANS_TABLES,NO_ZERO_DATE' );
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_posts` (`ID`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`)
				VALUES (1, '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-00-15 14:15:27', '2020-01-01 00:00:00')"
			)
		);

		$rows = $driver->query( 'SELECT post_modified FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2020-00-15 14:15:27', $rows[0]->post_modified );
	}

	/**
	 * Tests non-strict INSERT normalizes partial-zero dates when NO_ZERO_IN_DATE is enabled.
	 */
	public function test_non_strict_insert_normalizes_zero_in_dates_when_no_zero_in_date_mode_is_enabled(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( 'NO_ZERO_IN_DATE' );
		$this->install_posts_datetime_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_posts` (`ID`, `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`)
				VALUES (1, '2020-01-01 00:00:00', '2020-01-01 00:00:00', '2020-00-15 14:15:27', '2020-01-01 00:00:00')"
			)
		);

		$rows = $driver->query( 'SELECT post_modified FROM wptests_posts WHERE ID = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '0000-00-00 00:00:00', $rows[0]->post_modified );
	}

	/**
	 * Tests strict INSERT normalizes accepted temporal/YEAR values and rejects invalid scalar temporal values.
	 */
	public function test_strict_insert_normalizes_temporal_and_year_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_strict_dml_values_table_with_mysql_metadata( $driver );

		try {
			$driver->query( 'INSERT INTO `wptests_strict_values` (`id`, `date_value`) VALUES (1, TRUE)' );
			$this->fail( 'Expected invalid date scalar to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect date value: '1'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_strict_values` (`id`, `date_value`, `datetime_value`, `timestamp_value`, `year_value`)
				VALUES (2, '2025-10-23 18:30:00.123456', '2025-10-23', '2025-10-23 18:30:00.123456', 50)"
			)
		);

		$rows = $driver->query( 'SELECT date_value, datetime_value, timestamp_value, year_value FROM wptests_strict_values WHERE id = 2' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2025-10-23', $rows[0]->date_value );
		$this->assertSame( '2025-10-23 00:00:00', $rows[0]->datetime_value );
		$this->assertSame( '2025-10-23 18:30:00', $rows[0]->timestamp_value );
		$this->assertSame( '2050', $rows[0]->year_value );

		foreach ( array( '-1', '1900', '2156' ) as $value ) {
			try {
				$driver->query( "INSERT INTO `wptests_strict_values` (`id`, `year_value`) VALUES (3, {$value})" );
				$this->fail( 'Expected invalid YEAR value to be rejected in strict SQL mode.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( "Out of range value: '{$value}'", $e->getMessage(), $value );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $value );
			}
		}
	}

	/**
	 * Tests strict UPDATE normalizes accepted temporal/YEAR values and rejects invalid scalar temporal values.
	 */
	public function test_strict_update_normalizes_temporal_and_year_literals_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_strict_dml_values_table_with_mysql_metadata( $driver );
		$driver->query(
			"INSERT INTO `wptests_strict_values` (`id`, `date_value`, `datetime_value`, `timestamp_value`, `year_value`)
			VALUES (1, '2025-01-01', '2025-01-01 00:00:00', '2025-01-01 00:00:00', '2025')"
		);

		$this->assertSame(
			1,
			$driver->query(
				"UPDATE `wptests_strict_values`
				SET `date_value` = '2025-11-24 02:03:04.999999',
					`datetime_value` = '2025-11-24',
					`timestamp_value` = '2025-11-24 02:03:04.999999',
					`year_value` = 70
				WHERE `id` = 1"
			)
		);

		$rows = $driver->query( 'SELECT date_value, datetime_value, timestamp_value, year_value FROM wptests_strict_values WHERE id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2025-11-24', $rows[0]->date_value );
		$this->assertSame( '2025-11-24 00:00:00', $rows[0]->datetime_value );
		$this->assertSame( '2025-11-24 02:03:04', $rows[0]->timestamp_value );
		$this->assertSame( '1970', $rows[0]->year_value );

		try {
			$driver->query( 'UPDATE `wptests_strict_values` SET `datetime_value` = FALSE WHERE `id` = 1' );
			$this->fail( 'Expected invalid datetime scalar to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect datetime value: '0'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests strict integer DML rejects impossible coercions and MySQL range violations.
	 */
	public function test_strict_integer_literals_reject_invalid_values_and_out_of_range_values(): void {
		$driver = $this->create_driver();
		$this->install_strict_integer_values_table_with_mysql_metadata( $driver );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO `wptests_strict_ints` (`id`, `int_value`, `tiny_unsigned`, `small_value`, `int_unsigned`)
				VALUES (1, '3.0', TRUE, 32767, 4294967295)"
			)
		);

		$rows = $driver->query( 'SELECT int_value, tiny_unsigned, small_value, int_unsigned FROM wptests_strict_ints WHERE id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '3', $rows[0]->int_value );
		$this->assertSame( '1', $rows[0]->tiny_unsigned );
		$this->assertSame( '32767', $rows[0]->small_value );
		$this->assertSame( '4294967295', $rows[0]->int_unsigned );

		$cases = array(
			"INSERT INTO `wptests_strict_ints` (`id`, `int_value`) VALUES (2, 'abc')" => "Incorrect integer value: 'abc'",
			"INSERT INTO `wptests_strict_ints` (`id`, `int_value`) VALUES (2, '12abc')" => "Incorrect integer value: '12abc'",
			'INSERT INTO `wptests_strict_ints` (`id`, `tiny_unsigned`) VALUES (2, -1)' => "Out of range value: '-1'",
			'INSERT INTO `wptests_strict_ints` (`id`, `tiny_unsigned`) VALUES (2, 256)' => "Out of range value: '256'",
			'UPDATE `wptests_strict_ints` SET `small_value` = 32768 WHERE `id` = 1' => "Out of range value: '32768'",
			'UPDATE `wptests_strict_ints` SET `int_unsigned` = -1 WHERE `id` = 1' => "Out of range value: '-1'",
		);

		foreach ( $cases as $query => $message ) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected invalid integer value to be rejected in strict SQL mode.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests strict text DML rejects truncation using MySQL metadata.
	 */
	public function test_strict_text_literals_reject_truncation_from_mysql_metadata(): void {
		$driver = $this->create_driver();
		$this->install_strict_text_values_table_with_mysql_metadata( $driver );

		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_strict_texts` (`id`, `varchar_value`, `char_value`, `tinytext_value`) VALUES (1, 'abc', 'xyz', 'short')" ) );

		$long_tinytext = str_repeat( 'x', 256 );
		$cases         = array(
			"INSERT INTO `wptests_strict_texts` (`id`, `varchar_value`) VALUES (2, 'abcd')" => "Data too long for column 'varchar_value'",
			"UPDATE `wptests_strict_texts` SET `char_value` = 'abcd' WHERE `id` = 1" => "Data too long for column 'char_value'",
			"INSERT INTO `wptests_strict_texts` (`id`, `tinytext_value`) VALUES (2, '{$long_tinytext}')" => "Data too long for column 'tinytext_value'",
		);

		foreach ( $cases as $query => $message ) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected text truncation to be rejected in strict SQL mode.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
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
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported ON DUPLICATE KEY UPDATE statement.', $e->getMessage() );
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
	 * Tests REPLACE accepts MySQL's optional INTO keyword.
	 */
	public function test_simple_replace_without_into_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_without_into ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_replace_without_into ("ID", display_name) VALUES (2, \'old\')' );

		$replace = "REPLACE `wptests_replace_without_into` (`ID`, `display_name`) VALUES (2, 'new')";

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_replace_without_into" ("ID", "display_name") VALUES (2, \'new\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT display_name FROM wptests_replace_without_into WHERE "ID" = 2' );
		$this->assertSame( 'new', $rows[0]->display_name );
	}

	/**
	 * Tests REPLACE ... SET statements use PostgreSQL upserts and MySQL row counts.
	 */
	public function test_replace_set_with_known_conflict_column_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_options (
				option_id INTEGER PRIMARY KEY,
				option_name TEXT NOT NULL UNIQUE,
				option_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_options (option_id, option_name, option_value) VALUES (1, 'siteurl', 'old')" );

		$replace = "REPLACE INTO `wptests_options` SET `option_id` = 8, `option_name` = 'siteurl', `option_value` = 'updated'";

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_options" ("option_id", "option_name", "option_value") VALUES (8, \'siteurl\', \'updated\') ON CONFLICT ("option_name") DO UPDATE SET "option_id" = excluded."option_id", "option_name" = excluded."option_name", "option_value" = excluded."option_value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( "SELECT option_id, option_value FROM wptests_options WHERE option_name = 'siteurl'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '8', $rows[0]->option_id );
		$this->assertSame( 'updated', $rows[0]->option_value );

		$replace = "REPLACE `wptests_options` SET `option_id` = 9, `option_name` = 'home', `option_value` = 'created'";

		$this->assertSame( 1, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_options" ("option_id", "option_name", "option_value") VALUES (9, \'home\', \'created\') ON CONFLICT ("option_name") DO UPDATE SET "option_id" = excluded."option_id", "option_name" = excluded."option_name", "option_value" = excluded."option_value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT option_name, option_value FROM wptests_options ORDER BY option_id' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'siteurl', $rows[0]->option_name );
		$this->assertSame( 'updated', $rows[0]->option_value );
		$this->assertSame( 'home', $rows[1]->option_name );
		$this->assertSame( 'created', $rows[1]->option_value );
	}

	/**
	 * Tests multi-row REPLACE statements use PostgreSQL upserts and MySQL row counts.
	 */
	public function test_multi_row_replace_with_known_conflict_column_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_multi ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_replace_multi ("ID", display_name) VALUES (2, \'old\')' );

		$replace = "REPLACE INTO `wptests_replace_multi` (`ID`, `display_name`) VALUES (2, 'updated'), (3, 'new')";

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_replace_multi" ("ID", "display_name") VALUES (2, \'updated\'), (3, \'new\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT "ID", display_name FROM wptests_replace_multi ORDER BY "ID"' );
		$this->assertEquals(
			array(
				(object) array(
					'ID'           => '2',
					'display_name' => 'updated',
				),
				(object) array(
					'ID'           => '3',
					'display_name' => 'new',
				),
			),
			$rows
		);
	}

	/**
	 * Tests REPLACE uses MySQL unique-key metadata beyond WordPress heuristics.
	 */
	public function test_replace_uses_metadata_unique_key_conflict_target(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_replace_unique_slug (
				id int(11) NOT NULL,
				slug varchar(191) NOT NULL,
				value text NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug_key (slug)
			) DEFAULT CHARACTER SET utf8mb4'
		);
		$driver->query( "INSERT INTO wptests_replace_unique_slug (id, slug, value) VALUES (1, 'same', 'old')" );

		$replace = "REPLACE INTO wptests_replace_unique_slug (id, slug, value) VALUES (2, 'same', 'new'), (3, 'other', 'created')";

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertStringContainsString(
			'ON CONFLICT ("slug") DO UPDATE SET',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, slug, value FROM wptests_replace_unique_slug ORDER BY slug' );
		$this->assertEquals(
			array(
				(object) array(
					'id'    => '3',
					'slug'  => 'other',
					'value' => 'created',
				),
				(object) array(
					'id'    => '2',
					'slug'  => 'same',
					'value' => 'new',
				),
			),
			$rows
		);
	}

	/**
	 * Tests REPLACE uses composite unique-key metadata.
	 */
	public function test_replace_uses_metadata_composite_unique_key_conflict_target(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_replace_composite_unique (
				site_id int(11) NOT NULL,
				slug varchar(191) NOT NULL,
				value text NOT NULL,
				UNIQUE KEY site_slug (site_id, slug)
			) DEFAULT CHARACTER SET utf8mb4'
		);
		$driver->query( "INSERT INTO wptests_replace_composite_unique (site_id, slug, value) VALUES (1, 'same', 'old')" );

		$replace = "REPLACE INTO wptests_replace_composite_unique (site_id, slug, value) VALUES (1, 'same', 'new'), (2, 'same', 'created')";

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertStringContainsString(
			'ON CONFLICT ("site_id", "slug") DO UPDATE SET',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT site_id, slug, value FROM wptests_replace_composite_unique ORDER BY site_id' );
		$this->assertEquals(
			array(
				(object) array(
					'site_id' => '1',
					'slug'    => 'same',
					'value'   => 'new',
				),
				(object) array(
					'site_id' => '2',
					'slug'    => 'same',
					'value'   => 'created',
				),
			),
			$rows
		);
	}

	/**
	 * Tests REPLACE uses prefix unique-key metadata.
	 */
	public function test_replace_uses_metadata_prefix_unique_key_conflict_target(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_replace_prefix_unique (
				slug varchar(255) NOT NULL,
				value text NOT NULL,
				UNIQUE KEY slug_prefix (slug(10))
			) DEFAULT CHARACTER SET utf8mb4'
		);
		$driver->query( "INSERT INTO wptests_replace_prefix_unique (slug, value) VALUES ('existing-slug-one', 'old')" );

		$replace = "REPLACE INTO wptests_replace_prefix_unique (slug, value) VALUES ('existing-slug-two', 'new')";

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertStringContainsString(
			'ON CONFLICT (SUBSTR(CAST("slug" AS text), 1, 10)) DO UPDATE SET',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT slug, value FROM wptests_replace_prefix_unique' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'existing-slug-two', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
	}

	/**
	 * Tests REPLACE ... SELECT statements use PostgreSQL upserts and MySQL row counts.
	 */
	public function test_replace_select_with_known_conflict_column_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_select (id INTEGER PRIMARY KEY, name TEXT NOT NULL, color TEXT NOT NULL)' );
		$driver->query( 'CREATE TABLE wptests_replace_select_source (id INTEGER NOT NULL, name TEXT NOT NULL, color TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_replace_select (id, name, color) VALUES (1, 'old', 'red')" );
		$driver->query( "INSERT INTO wptests_replace_select_source (id, name, color) VALUES (1, 'updated', 'blue'), (2, 'new', 'green')" );

		$replace = 'REPLACE INTO wptests_replace_select (`id`, `name`, `color`)
			SELECT id, name, color FROM wptests_replace_select_source WHERE 1 = 1';

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO wptests_replace_select ("id", "name", "color") SELECT id, name, color FROM wptests_replace_select_source WHERE 1 = 1 ON CONFLICT ("id") DO UPDATE SET "id" = excluded."id", "name" = excluded."name", "color" = excluded."color"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, name, color FROM wptests_replace_select ORDER BY id' );
		$this->assertEquals(
			array(
				(object) array(
					'id'    => '1',
					'name'  => 'updated',
					'color' => 'blue',
				),
				(object) array(
					'id'    => '2',
					'name'  => 'new',
					'color' => 'green',
				),
			),
			$rows
		);
	}

	/**
	 * Tests columnless REPLACE ... SELECT infers target columns from MySQL metadata.
	 */
	public function test_columnless_replace_select_uses_mysql_metadata_columns(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_select_columnless ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_replace_select_columnless (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				display_name varchar(250) NOT NULL DEFAULT "",
				PRIMARY KEY (ID)
			)'
		);
		$driver->query( 'CREATE TABLE wptests_replace_select_columnless_source ("ID" INTEGER NOT NULL, display_name TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_replace_select_columnless ("ID", display_name) VALUES (2, \'old\')' );
		$driver->query( 'INSERT INTO wptests_replace_select_columnless_source ("ID", display_name) VALUES (2, \'updated\'), (3, \'new\')' );

		$replace = 'REPLACE INTO wptests_replace_select_columnless
			SELECT `ID`, display_name FROM wptests_replace_select_columnless_source WHERE 1 = 1';

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO wptests_replace_select_columnless ("ID", "display_name") SELECT ' . $this->get_expected_mysql_integer_cast_sql( '"ID"' ) . ' , CAST(display_name AS text) FROM wptests_replace_select_columnless_source WHERE 1 = 1 ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT "ID", display_name FROM wptests_replace_select_columnless ORDER BY "ID"' );
		$this->assertSame( 'updated', $rows[0]->display_name );
		$this->assertSame( 'new', $rows[1]->display_name );
	}

	/**
	 * Tests columnless multi-row REPLACE statements infer target columns from MySQL metadata.
	 */
	public function test_columnless_multi_row_replace_uses_mysql_metadata_columns(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_columnless ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_replace_columnless (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				display_name varchar(250) NOT NULL DEFAULT "",
				PRIMARY KEY (ID)
			)'
		);
		$driver->query( 'INSERT INTO wptests_replace_columnless ("ID", display_name) VALUES (2, \'old\')' );

		$replace = "REPLACE INTO `wptests_replace_columnless` VALUES (2, 'updated'), (3, 'new')";

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_replace_columnless" ("ID", "display_name") VALUES (2, \'updated\'), (3, \'new\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT "ID", display_name FROM wptests_replace_columnless ORDER BY "ID"' );
		$this->assertSame( 'updated', $rows[0]->display_name );
		$this->assertSame( 'new', $rows[1]->display_name );
	}

	/**
	 * Tests duplicate conflict keys in one REPLACE batch run sequentially.
	 */
	public function test_multi_row_replace_with_duplicate_conflict_values_runs_sequentially(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_duplicate ("ID" INTEGER PRIMARY KEY, display_name TEXT NOT NULL)' );

		$replace = "REPLACE INTO `wptests_replace_duplicate` (`ID`, `display_name`) VALUES (4, 'first'), (4, 'second')";

		$this->assertSame( 3, $driver->query( $replace ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_replace_duplicate" ("ID", "display_name") VALUES (4, \'first\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
					'params' => array(),
				),
				array(
					'sql'    => 'INSERT INTO "wptests_replace_duplicate" ("ID", "display_name") VALUES (4, \'second\') ON CONFLICT ("ID") DO UPDATE SET "ID" = excluded."ID", "display_name" = excluded."display_name"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT display_name FROM wptests_replace_duplicate WHERE "ID" = 4' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'second', $rows[0]->display_name );
	}

	/**
	 * Tests multi-row REPLACE without a known conflict column falls back to INSERT.
	 */
	public function test_multi_row_replace_without_known_conflict_column_is_inserted(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_replace_multi_plain (post_name TEXT NOT NULL, post_status TEXT NOT NULL)' );

		$replace = "REPLACE INTO `wptests_replace_multi_plain` (`post_name`, `post_status`) VALUES ('hello-world', 'publish'), ('about', 'draft')";

		$this->assertSame( 2, $driver->query( $replace ) );
		$this->assertSame(
			'INSERT INTO "wptests_replace_multi_plain" ("post_name", "post_status") VALUES (\'hello-world\', \'publish\'), (\'about\', \'draft\')',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT post_name, post_status FROM wptests_replace_multi_plain ORDER BY post_name' );
		$this->assertEquals(
			array(
				(object) array(
					'post_name'   => 'about',
					'post_status' => 'draft',
				),
				(object) array(
					'post_name'   => 'hello-world',
					'post_status' => 'publish',
				),
			),
			$rows
		);
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
		$driver->set_sql_mode( '' );
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
	 * Tests standalone CREATE INDEX updates PostgreSQL schema and MySQL metadata.
	 */
	public function test_standalone_create_index_updates_postgresql_and_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_standalone_index (
				id int NOT NULL,
				value varchar(255) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_index (
				id int NOT NULL,
				value varchar(255) NOT NULL,
				PRIMARY KEY (id)
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE INDEX idx_value ON wptests_standalone_index (value(16) DESC) COMMENT "Lookup"' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE INDEX "wptests_standalone_index__idx_value" ON "wptests_standalone_index" ("value" DESC)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_standalone_index' );
		$this->assertSame( array( 'PRIMARY', 'idx_value' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( 'value', $indexes[1]['column_name'] );
		$this->assertSame( '1', $indexes[1]['non_unique'] );
		$this->assertSame( 'BTREE', $indexes[1]['index_type'] );
		$this->assertSame( 'D', $indexes[1]['collation'] );
		$this->assertSame( '16', $indexes[1]['sub_part'] );
		$this->assertSame( '', $indexes[1]['nullable'] );

		$show_indexes = $driver->query( "SHOW INDEX FROM wptests_standalone_index WHERE Index_comment = 'Lookup'" );

		$this->assertCount( 1, $show_indexes );
		$this->assertSame( 'idx_value', $show_indexes[0]->Key_name );
		$this->assertSame( 'D', $show_indexes[0]->Collation );
		$this->assertSame( 'Lookup', $show_indexes[0]->Index_comment );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_standalone_index' )[0]->{'Create Table'};
		$this->assertStringContainsString( "KEY `idx_value` (`value`(16) DESC) COMMENT 'Lookup'", $create_table );
	}

	/**
	 * Tests standalone CREATE UNIQUE INDEX supports MySQL prefix key parts.
	 */
	public function test_standalone_create_unique_prefix_index_updates_postgresql_and_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_standalone_prefix_unique (
				value varchar(255) NOT NULL,
				label varchar(255) NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_prefix_unique (
				value varchar(255) NOT NULL,
				label varchar(255) NOT NULL
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE UNIQUE INDEX value_prefix ON wptests_standalone_prefix_unique (value(16))' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE UNIQUE INDEX "wptests_standalone_prefix_unique__value_prefix" ON "wptests_standalone_prefix_unique" (SUBSTR(CAST("value" AS text), 1, 16))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_standalone_prefix_unique' );
		$this->assertSame( array( 'value_prefix' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( 'value', $indexes[0]['column_name'] );
		$this->assertSame( '0', $indexes[0]['non_unique'] );
		$this->assertSame( '16', $indexes[0]['sub_part'] );
	}

	/**
	 * Tests standalone CREATE/DROP INDEX ignore supported MySQL-only options.
	 */
	public function test_standalone_create_and_drop_index_ignore_supported_mysql_options(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_standalone_index_options (
				id int NOT NULL,
				value varchar(255) NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_index_options (
				id int NOT NULL,
				value varchar(255) NOT NULL
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE INDEX idx_value USING BTREE ON wptests_standalone_index_options (value) KEY_BLOCK_SIZE 8 INVISIBLE ALGORITHM DEFAULT LOCK NONE' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE INDEX "wptests_standalone_index_options__idx_value" ON "wptests_standalone_index_options" ("value")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame(
			0,
			$driver->query( 'DROP INDEX idx_value ON wptests_standalone_index_options ALGORITHM=INPLACE LOCK=SHARED' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_standalone_index_options__idx_value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE INDEX idx_visible ON wptests_standalone_index_options (value) KEY_BLOCK_SIZE=16 VISIBLE ALGORITHM=COPY LOCK=EXCLUSIVE' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE INDEX "wptests_standalone_index_options__idx_visible" ON "wptests_standalone_index_options" ("value")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_standalone_index_options' );
		$this->assertSame( array( 'idx_visible' ), array_column( $indexes, 'key_name' ) );
	}

	/**
	 * Tests standalone FULLTEXT/SPATIAL CREATE INDEX updates MySQL metadata without PostgreSQL index DDL.
	 */
	public function test_standalone_fulltext_and_spatial_create_index_are_metadata_only(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_search_geo (
				id int NOT NULL,
				body longtext NOT NULL,
				shape point NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_search_geo (
				id int NOT NULL,
				body longtext NOT NULL,
				shape point NOT NULL
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE FULLTEXT INDEX body_fulltext ON wptests_search_geo (body)' )
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$this->assertSame(
			0,
			$driver->query( 'CREATE SPATIAL INDEX shape_spatial ON wptests_search_geo (shape)' )
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_search_geo' );
		$this->assertSame( array( 'body_fulltext', 'shape_spatial' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( array( 'FULLTEXT', 'SPATIAL' ), array_column( $indexes, 'index_type' ) );
		$this->assertNull( $indexes[0]['sub_part'] );
		$this->assertSame( '32', $indexes[1]['sub_part'] );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_search_geo' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  SPATIAL KEY `shape_spatial` (`shape`(32))', $create_table );
		$this->assertStringContainsString( '  FULLTEXT KEY `body_fulltext` (`body`)', $create_table );

		$this->assertSame( 0, $driver->query( 'DROP INDEX body_fulltext ON wptests_search_geo' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array( 'shape_spatial' ), array_column( $this->get_mysql_index_metadata_rows( $driver, 'wptests_search_geo' ), 'key_name' ) );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_search_geo DROP KEY shape_spatial' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_search_geo' ) );
	}

	/**
	 * Tests install-translated quoted CREATE INDEX IF NOT EXISTS DDL updates MySQL metadata.
	 */
	public function test_standalone_create_index_accepts_install_translated_if_not_exists_quoted_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_options (
				option_name varchar(191) NOT NULL,
				option_value varchar(255) NOT NULL,
				PRIMARY KEY (option_name)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_options (
				option_name varchar(191) NOT NULL,
				option_value varchar(255) NOT NULL,
				PRIMARY KEY (option_name)
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE INDEX IF NOT EXISTS "wptests_options__option_value" ON "wptests_options" ("option_value")' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE INDEX IF NOT EXISTS "wptests_options__option_value" ON "wptests_options" ("option_value")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_options' );
		$this->assertSame( array( 'PRIMARY', 'option_value' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( 'option_value', $indexes[1]['column_name'] );
		$this->assertSame( '1', $indexes[1]['non_unique'] );
		$this->assertSame( 'BTREE', $indexes[1]['index_type'] );
	}

	/**
	 * Tests schema-qualified install-translated CREATE INDEX IF NOT EXISTS DDL updates MySQL metadata.
	 */
	public function test_standalone_create_index_accepts_schema_qualified_install_translated_if_not_exists_quoted_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_schema_quoted_index (
				option_name varchar(191) NOT NULL,
				option_value varchar(255) NOT NULL,
				PRIMARY KEY (option_name)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_schema_quoted_index (
				option_name varchar(191) NOT NULL,
				option_value varchar(255) NOT NULL,
				PRIMARY KEY (option_name)
			)'
		);

		$this->assertSame(
			0,
			$driver->query( 'CREATE INDEX IF NOT EXISTS "wptests_schema_quoted_index__option_value" ON "wptests"."wptests_schema_quoted_index" ("option_value")' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'CREATE INDEX IF NOT EXISTS "wptests_schema_quoted_index__option_value" ON "wptests_schema_quoted_index" ("option_value")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_schema_quoted_index' );
		$this->assertSame( array( 'PRIMARY', 'option_value' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( 'option_value', $indexes[1]['column_name'] );
		$this->assertSame( '1', $indexes[1]['non_unique'] );
		$this->assertSame( 'BTREE', $indexes[1]['index_type'] );
	}

	/**
	 * Tests standalone CREATE INDEX keeps the index name unqualified for PostgreSQL.
	 */
	public function test_standalone_create_index_with_public_schema_qualifies_table_only(): void {
		$driver     = $this->create_driver();
		$connection = $driver->get_connection();
		$pdo        = $connection->get_pdo();

		$pdo->exec( "ATTACH DATABASE ':memory:' AS public" );
		$pdo->exec(
			sprintf(
				'CREATE TABLE %s.%s (%s TEXT)',
				$connection->quote_identifier( 'public' ),
				$connection->quote_identifier( 'wptests_public_index' ),
				$connection->quote_identifier( 'value' )
			)
		);

		$translate_create_index = new ReflectionMethod( WP_PostgreSQL_Driver::class, 'translate_mysql_create_index_query' );
		if ( PHP_VERSION_ID < 80100 ) {
			$translate_create_index->setAccessible( true );
		}

		$translation = $translate_create_index->invoke(
			$driver,
			'CREATE INDEX idx_value ON wptests_public_index (value)'
		);

		$this->assertIsArray( $translation );
		$this->assertSame(
			array(
				'CREATE INDEX "wptests_public_index__idx_value" ON "public"."wptests_public_index" ("value")',
			),
			$translation['statements']
		);
	}

	/**
	 * Tests standalone CREATE UNIQUE INDEX participates in ON DUPLICATE KEY UPDATE translation.
	 */
	public function test_standalone_create_unique_index_updates_upsert_conflict_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_standalone_unique_index (slug varchar(191) NOT NULL, value int NOT NULL)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_unique_index (
				slug varchar(191) NOT NULL,
				value int NOT NULL
			)'
		);
		$driver->query( 'CREATE UNIQUE INDEX slug_lookup ON wptests_standalone_unique_index (slug)' );

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_standalone_unique_index (`slug`, `value`)
				VALUES ('alpha', 1)
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
			)
		);

		$this->assertSame(
			'INSERT INTO "wptests_standalone_unique_index" ("slug", "value") VALUES (\'alpha\', 1) ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests standalone DROP INDEX removes PostgreSQL schema and MySQL metadata.
	 */
	public function test_standalone_drop_index_updates_postgresql_and_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_standalone_drop_index (
				id int NOT NULL,
				value varchar(255) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_drop_index (
				id int NOT NULL,
				value varchar(255) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( 'CREATE INDEX idx_value ON wptests_standalone_drop_index (value)' );

		$this->assertSame( 0, $driver->query( 'DROP INDEX idx_value ON wptests_standalone_drop_index' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_standalone_drop_index__idx_value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_standalone_drop_index' );
		$this->assertSame( array( 'PRIMARY' ), array_column( $indexes, 'key_name' ) );
	}

	/**
	 * Tests standalone DROP INDEX PRIMARY removes the primary-key constraint metadata.
	 */
	public function test_standalone_drop_index_primary_updates_postgresql_and_mysql_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_standalone_drop_primary (
				id int NOT NULL,
				value varchar(255) NOT NULL,
				PRIMARY KEY (id),
				KEY value_idx (value)
			)'
		);

		$this->assertSame( 0, $driver->query( 'DROP INDEX PRIMARY ON wptests_standalone_drop_primary' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_standalone_drop_primary" DROP CONSTRAINT "wptests_standalone_drop_primary_pkey"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_standalone_drop_primary' );
		$this->assertSame( array( 'value_idx' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
	}

	/**
	 * Tests ALTER TABLE DROP INDEX removes PostgreSQL schema and MySQL metadata.
	 */
	public function test_alter_table_drop_index_updates_postgresql_and_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_alter_drop_index (
				id int NOT NULL,
				option_name varchar(191) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_drop_index (
				id int NOT NULL,
				option_name varchar(191) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( 'CREATE INDEX option_name ON wptests_alter_drop_index (option_name)' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_drop_index DROP INDEX option_name' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_alter_drop_index__option_name"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_drop_index' );
		$this->assertSame( array( 'PRIMARY' ), array_column( $indexes, 'key_name' ) );
	}

	/**
	 * Tests ALTER TABLE DROP INDEX accepts backticked identifiers.
	 */
	public function test_alter_table_drop_index_accepts_backticked_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_alter_drop_backtick_index (
				option_name varchar(191) NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_drop_backtick_index (
				option_name varchar(191) NOT NULL
			)'
		);
		$driver->query( 'CREATE INDEX option_name ON wptests_alter_drop_backtick_index (option_name)' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE `wptests_alter_drop_backtick_index` DROP INDEX `option_name`' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_alter_drop_backtick_index__option_name"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_drop_backtick_index' ) );
	}

	/**
	 * Tests ALTER TABLE DROP KEY removes PostgreSQL schema and MySQL metadata.
	 */
	public function test_alter_table_drop_key_updates_postgresql_and_mysql_metadata(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_alter_drop_key (
				option_name varchar(191) NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_drop_key (
				option_name varchar(191) NOT NULL
			)'
		);
		$driver->query( 'CREATE INDEX option_name ON wptests_alter_drop_key (option_name)' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_drop_key DROP KEY option_name' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_alter_drop_key__option_name"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_drop_key' ) );
	}

	/**
	 * Tests ALTER TABLE ADD primary and unique constraints update backend and MySQL metadata.
	 */
	public function test_alter_table_add_primary_and_unique_constraints_update_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_add_constraints (
				id int NOT NULL,
				slug varchar(191) NOT NULL
			)'
		);

		$driver->query(
			'ALTER TABLE wptests_alter_add_constraints
				ADD CONSTRAINT ignored_primary_name PRIMARY KEY (id),
				ADD CONSTRAINT slug_unique UNIQUE (slug)'
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_add_constraints" ADD PRIMARY KEY ("id")',
					'params' => array(),
				),
				array(
					'sql'    => 'CREATE UNIQUE INDEX "wptests_alter_add_constraints__slug_unique" ON "wptests_alter_add_constraints" ("slug")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_add_constraints' );

		$this->assertSame( array( 'PRIMARY', 'slug_unique' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
		$this->assertSame( array( '0' ), array_values( array_unique( array_column( $indexes, 'non_unique' ) ) ) );
	}

	/**
	 * Tests ALTER TABLE DROP primary-key forms update backend and MySQL metadata.
	 */
	public function test_alter_table_drop_primary_key_forms_update_backend_and_metadata(): void {
		$queries = array(
			'ALTER TABLE wptests_alter_drop_primary DROP PRIMARY KEY',
			'ALTER TABLE wptests_alter_drop_primary DROP INDEX PRIMARY',
			'ALTER TABLE wptests_alter_drop_primary DROP KEY `PRIMARY`',
		);

		foreach ( $queries as $query ) {
			$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
			$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
			$this->install_information_schema_fixture( $driver );
			$driver->store_mysql_schema_metadata(
				'CREATE TABLE wptests_alter_drop_primary (
					id int NOT NULL,
					slug varchar(191) NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY slug (slug)
				)'
			);

			$this->assertSame( 0, $driver->query( $query ), $query );
			$this->assertSame(
				array(
					array(
						'sql'    => 'ALTER TABLE "wptests_alter_drop_primary" DROP CONSTRAINT "wptests_alter_drop_primary_pkey"',
						'params' => array(),
					),
				),
				$driver->get_last_postgresql_queries(),
				$query
			);

			$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_drop_primary' );
			$this->assertSame( array( 'slug' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ), $query );
		}
	}

	/**
	 * Tests ALTER TABLE DROP CONSTRAINT maps unique-key metadata to a PostgreSQL index drop.
	 */
	public function test_alter_table_drop_constraint_updates_unique_key_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_drop_constraint (
				id int NOT NULL,
				name varchar(191) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY name_unique (name)
			)'
		);
		$driver->get_connection()->get_pdo()->exec( 'CREATE TABLE wptests_alter_drop_constraint (id INTEGER, name TEXT)' );
		$driver->get_connection()->get_pdo()->exec( 'CREATE UNIQUE INDEX "wptests_alter_drop_constraint__name_unique" ON wptests_alter_drop_constraint (name)' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_drop_constraint DROP CONSTRAINT name_unique' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP INDEX "wptests_alter_drop_constraint__name_unique"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_drop_constraint' );

		$this->assertSame( array( 'PRIMARY' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
	}

	/**
	 * Tests ALTER TABLE ADD/DROP CHECK forms translate to PostgreSQL constraints.
	 */
	public function test_alter_table_check_constraint_forms_translate_to_postgresql(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_check (
				id int NOT NULL
			)'
		);

		$driver->query(
			'ALTER TABLE wptests_alter_check
				ADD CONSTRAINT positive_id CHECK (id > 0),
				ADD CHECK (id < 10),
				ADD CHECK (id > 1)'
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" ADD CONSTRAINT "positive_id" CHECK (id > 0)',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" ADD CONSTRAINT "wptests_alter_check_chk_1" CHECK (id < 10)',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" ADD CONSTRAINT "wptests_alter_check_chk_2" CHECK (id > 1)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$driver->query(
			'ALTER TABLE wptests_alter_check
				DROP CONSTRAINT positive_id,
				DROP CHECK wptests_alter_check_chk_1,
				DROP CHECK wptests_alter_check_chk_2'
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" DROP CONSTRAINT "positive_id"',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" DROP CONSTRAINT "wptests_alter_check_chk_1"',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_check" DROP CONSTRAINT "wptests_alter_check_chk_2"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
	}

	/**
	 * Tests main database-qualified standalone index statements target public table metadata.
	 */
	public function test_standalone_index_accepts_main_database_qualified_table_names(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_qualified_index (id int NOT NULL, name varchar(191) NOT NULL)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_qualified_index (
				id int NOT NULL,
				name varchar(191) NOT NULL
			)'
		);

		$this->assertSame( 0, $driver->query( 'CREATE INDEX idx_name ON wptests.wptests_qualified_index (name)' ) );
		$this->assertSame(
			'CREATE INDEX "wptests_qualified_index__idx_name" ON "wptests_qualified_index" ("name")',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
		$this->assertSame(
			array( 'idx_name' ),
			array_column( $this->get_mysql_index_metadata_rows( $driver, 'wptests_qualified_index' ), 'key_name' )
		);

		$this->assertSame( 0, $driver->query( 'DROP INDEX idx_name ON wptests.wptests_qualified_index' ) );
		$this->assertSame(
			'DROP INDEX "wptests_qualified_index__idx_name"',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_qualified_index' ) );
	}

	/**
	 * Tests main database-qualified DROP TABLE removes tables and MySQL metadata.
	 */
	public function test_drop_table_accepts_main_database_qualified_table_names(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_qualified_drop_one (id int NOT NULL, PRIMARY KEY (id))' );
		$driver->query( 'CREATE TABLE wptests_qualified_drop_two (id int NOT NULL, PRIMARY KEY (id))' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_qualified_drop_one (id int NOT NULL, PRIMARY KEY (id))' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_qualified_drop_two (id int NOT NULL, PRIMARY KEY (id))' );

		$this->assertNotSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_qualified_drop_one' ) );
		$this->assertNotSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_qualified_drop_two' ) );

		$this->assertSame(
			0,
			$driver->query( 'DROP TABLE IF EXISTS wptests.wptests_qualified_drop_one, wptests.wptests_qualified_drop_two' )
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'DROP TABLE IF EXISTS "wptests_qualified_drop_one"',
					'params' => array(),
				),
				array(
					'sql'    => 'DROP TABLE IF EXISTS "wptests_qualified_drop_two"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertFalse( $this->sqlite_table_exists( $driver, 'main', 'wptests_qualified_drop_one' ) );
		$this->assertFalse( $this->sqlite_table_exists( $driver, 'main', 'wptests_qualified_drop_two' ) );
		$this->assertSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_qualified_drop_one' ) );
		$this->assertSame( array(), $this->get_mysql_index_metadata_rows( $driver, 'wptests_qualified_drop_two' ) );
	}

	/**
	 * Tests unsupported standalone index DDL fails before backend execution.
	 */
	public function test_standalone_index_unsupported_syntax_does_not_reach_backend(): void {
		$queries = array(
			'CREATE INDEX idx_value USING HASH ON wptests_index_fail (value)',
			'CREATE INDEX idx_value ON wptests_index_fail (value) KEY_BLOCK_SIZE=bad',
			'CREATE INDEX idx_value ON wptests_index_fail (value) ALGORITHM=INSTANT',
			'CREATE INDEX idx_value ON wptests_index_fail (value) LOCK=UNKNOWN',
			'CREATE INDEX idx_value ON wptests_index_fail (value) ENGINE_ATTRIBUTE="{}"',
			'CREATE INDEX idx_value ON wptests_index_fail (value) WITH PARSER ngram',
			'CREATE FULLTEXT INDEX idx_value ON wptests_index_fail (value) COMMENT "Lookup"',
			'CREATE SPATIAL INDEX idx_value ON wptests_index_fail (value) KEY_BLOCK_SIZE=8',
			'CREATE INDEX IF NOT EXISTS "wptests_index_fail__" ON "wptests_index_fail" ("value")',
			'CREATE INDEX idx_value ON information_schema.tables (name)',
			'CREATE INDEX idx_value ON other_db.wptests_index_fail (value)',
			'DROP INDEX idx_value ON wptests_index_fail KEY_BLOCK_SIZE=8',
			'DROP INDEX idx_value ON wptests_index_fail ALGORITHM=INSTANT',
			'DROP INDEX idx_value ON wptests_index_fail LOCK=UNKNOWN',
			'DROP INDEX idx_value ON information_schema.tables',
			'DROP INDEX idx_value ON other_db.wptests_index_fail',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$driver->query( 'CREATE TABLE wptests_index_fail (value varchar(255) NOT NULL)' );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported standalone index DDL to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertContains(
					$e->getMessage(),
					array(
						'Unsupported CREATE INDEX statement.',
						'Unsupported DROP INDEX statement.',
						'Unsupported information_schema query.',
					),
					$query
				);
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests CREATE TABLE FULLTEXT/SPATIAL indexes update metadata without PostgreSQL index DDL.
	 */
	public function test_create_table_fulltext_and_spatial_indexes_are_metadata_only(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				'CREATE TABLE wptests_create_search_geo (
					id int NOT NULL,
					body text,
					shape point NOT NULL,
					FULLTEXT KEY body_fulltext (body),
					SPATIAL KEY shape_spatial (shape)
				)'
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TABLE \"wptests_create_search_geo\" (\n  \"id\" integer NOT NULL,\n  \"body\" text,\n  \"shape\" text NOT NULL\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_create_search_geo' );
		$this->assertSame( array( 'body_fulltext', 'shape_spatial' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( array( 'FULLTEXT', 'SPATIAL' ), array_column( $indexes, 'index_type' ) );
		$this->assertNull( $indexes[0]['sub_part'] );
		$this->assertSame( '32', $indexes[1]['sub_part'] );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_create_search_geo' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  SPATIAL KEY `shape_spatial` (`shape`(32))', $create_table );
		$this->assertStringContainsString( '  FULLTEXT KEY `body_fulltext` (`body`)', $create_table );
	}

	/**
	 * Tests CREATE TABLE index direction metadata is exposed through MySQL introspection.
	 */
	public function test_create_table_index_directions_update_mysql_introspection_metadata(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				'CREATE TABLE wptests_create_directional_index (
					id int NOT NULL,
					score int NOT NULL,
					name varchar(255) NOT NULL,
					created_at datetime NOT NULL,
					body text,
					PRIMARY KEY (id),
					KEY score_name (score ASC, name(16) DESC, created_at DESC),
					FULLTEXT KEY body_fulltext (body)
				)'
			)
		);

		$statistics = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME, COLLATION, SUB_PART
			FROM information_schema.statistics
			WHERE table_name = 'wptests_create_directional_index'
			ORDER BY INDEX_NAME, SEQ_IN_INDEX"
		);

		$statistics_by_part = array();
		foreach ( $statistics as $row ) {
			$statistics_by_part[ $row->INDEX_NAME . ':' . $row->COLUMN_NAME ] = array( $row->COLLATION, $row->SUB_PART );
		}
		ksort( $statistics_by_part );

		$this->assertSame(
			array(
				'PRIMARY:id'             => array( 'A', null ),
				'body_fulltext:body'     => array( null, null ),
				'score_name:created_at' => array( 'D', null ),
				'score_name:name'        => array( 'D', '16' ),
				'score_name:score'       => array( 'A', null ),
			),
			$statistics_by_part
		);

		$show_index = $driver->query( "SHOW INDEX FROM wptests_create_directional_index WHERE Key_name = 'score_name'" );
		$this->assertSame( array( 'A', 'D', 'D' ), array_column( $show_index, 'Collation' ) );
		$this->assertSame( array( null, '16', null ), array_column( $show_index, 'Sub_part' ) );

		$show_create = $driver->query( 'SHOW CREATE TABLE wptests_create_directional_index' )[0]->{'Create Table'};
		$this->assertStringContainsString(
			'  KEY `score_name` (`score`, `name`(16) DESC, `created_at` DESC)',
			$show_create
		);
		$this->assertStringContainsString( '  FULLTEXT KEY `body_fulltext` (`body`)', $show_create );
	}

	/**
	 * Tests CREATE TABLE zero-date defaults stay text-backed while SHOW CREATE preserves MySQL metadata.
	 */
	public function test_create_table_zero_date_defaults_are_text_and_show_create_preserves_mysql_shape(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				"CREATE TABLE wptests_zero_dates (
					id int NOT NULL,
					created_date date NOT NULL DEFAULT '0000-00-00',
					created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
					updated_at timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
					PRIMARY KEY (id)
				) DEFAULT CHARACTER SET utf8mb4"
			)
		);
		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TABLE \"wptests_zero_dates\" (\n  \"id\" integer NOT NULL,\n  \"created_date\" text NOT NULL DEFAULT '0000-00-00',\n  \"created_at\" text NOT NULL DEFAULT '0000-00-00 00:00:00',\n  \"updated_at\" text NOT NULL DEFAULT '0000-00-00 00:00:00',\n  PRIMARY KEY (\"id\")\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_zero_dates' )[0]->{'Create Table'};

		$this->assertStringContainsString( "  `created_date` date NOT NULL DEFAULT '0000-00-00'", $create_table );
		$this->assertStringContainsString( "  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00'", $create_table );
		$this->assertStringContainsString( "  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'", $create_table );
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
	 * Tests INSERT ... SELECT accepts MySQL's optional INTO keyword.
	 */
	public function test_insert_select_without_into_is_translated_with_postgresql_into(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_insert_select_without_into (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);

		$this->assertSame(
			1,
			$driver->query( "INSERT wptests_insert_select_without_into (`id`, `value`) SELECT 1, 'one' FROM DUAL" )
		);
		$this->assertSame(
			'INSERT INTO wptests_insert_select_without_into ("id", "value") SELECT 1, \'one\'',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, value FROM wptests_insert_select_without_into' );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'one', $rows[0]->value );
	}

	/**
	 * Tests unsupported generic DML shapes fail closed in the narrow translators.
	 */
	public function test_unsupported_simple_dml_shapes_return_null_translation(): void {
		$driver = $this->create_driver();

		$unsupported_query_methods = array(
			'UPDATE wptests_unsupported, wptests_other SET wptests_other.id = wptests_unsupported.id WHERE wptests_other.id = 1' => 'translate_simple_mysql_update_query',
		);

		foreach ( $unsupported_query_methods as $query => $method_name ) {
			if ( 'translate_simple_mysql_insert_query' === $method_name || 'translate_simple_mysql_replace_query' === $method_name ) {
				$this->assertNull(
					$this->translate_driver_query_data_with_private_method( $driver, $method_name, $query ),
					$query
				);
				continue;
			}

			$this->assertNull(
				$this->translate_driver_query_with_private_method( $driver, $method_name, $query ),
				$query
			);
		}
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
	 * Tests AUTO_INCREMENT zero values generate IDs unless NO_AUTO_VALUE_ON_ZERO is active.
	 */
	public function test_auto_increment_zero_respects_no_auto_value_on_zero_sql_mode(): void {
		$driver = $this->create_driver();

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

		$this->assertSame( 1, $driver->query( "INSERT INTO wptests_posts (`ID`, `post_title`) VALUES (0, 'zero')" ) );
		$this->assertSame(
			'INSERT INTO "wptests_posts" ("ID", "post_title") VALUES (NULL, \'zero\')',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 1, $driver->get_insert_id() );

		$this->assertSame( 1, $driver->query( "INSERT INTO wptests_posts (`ID`, `post_title`) VALUES ('0', 'quoted zero')" ) );
		$this->assertSame( 2, $driver->get_insert_id() );

		$driver->set_sql_mode( 'NO_AUTO_VALUE_ON_ZERO' );
		$this->assertSame( 1, $driver->query( "INSERT INTO wptests_posts (`ID`, `post_title`) VALUES (0, 'literal zero')" ) );
		$this->assertSame(
			'INSERT INTO "wptests_posts" ("ID", "post_title") VALUES (0, \'literal zero\')',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$rows = $driver->query( 'SELECT ID, post_title FROM wptests_posts ORDER BY ID' );
		$this->assertSame(
			array(
				array( '0', 'literal zero' ),
				array( '1', 'zero' ),
				array( '2', 'quoted zero' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->ID, $row->post_title );
				},
				$rows
			)
		);
	}

	/**
	 * Tests AUTO_INCREMENT zero handling applies to ON DUPLICATE KEY UPDATE VALUES rows.
	 */
	public function test_auto_increment_zero_respects_sql_mode_for_upsert_values(): void {
		$driver = $this->create_driver();
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (0, 'generated')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") VALUES (NULL, \'generated\') ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->set_sql_mode( 'NO_AUTO_VALUE_ON_ZERO' );
		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`) VALUES (0, 'literal zero')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") VALUES (0, \'literal zero\') ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert ORDER BY id' );
		$this->assertSame(
			array(
				array( '0', 'literal zero' ),
				array( '1', 'generated' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->value );
				},
				$rows
			)
		);
	}

	/**
	 * Tests AUTO_INCREMENT zero handling applies to INSERT ... SELECT projections.
	 */
	public function test_auto_increment_zero_respects_sql_mode_for_insert_select(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_insert_select_posts (
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				post_title TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_insert_select_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_title varchar(255) NOT NULL DEFAULT "",
				PRIMARY KEY (ID)
			)'
		);

		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_insert_select_posts` (`ID`, `post_title`) SELECT 0, 'generated' FROM DUAL" ) );
		$this->assertSame(
			'INSERT INTO "wptests_insert_select_posts" ("ID", "post_title") SELECT NULL , \'generated\'',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->set_sql_mode( 'NO_AUTO_VALUE_ON_ZERO' );
		$this->assertSame( 1, $driver->query( "INSERT INTO `wptests_insert_select_posts` (`ID`, `post_title`) SELECT 0, 'literal zero' FROM DUAL" ) );
		$this->assertSame(
			'INSERT INTO "wptests_insert_select_posts" ("ID", "post_title") SELECT 0, \'literal zero\'',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$rows = $driver->query( 'SELECT ID, post_title FROM wptests_insert_select_posts ORDER BY ID' );
		$this->assertSame(
			array(
				array( '0', 'literal zero' ),
				array( '1', 'generated' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->ID, $row->post_title );
				},
				$rows
			)
		);
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
	 * Tests INSERT ... SET upserts are normalized into PostgreSQL ON CONFLICT statements.
	 */
	public function test_insert_set_upsert_is_translated_to_postgresql_on_conflict(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );

		$insert = "INSERT INTO `wptests_options` SET `option_name` = 'siteurl',
			`option_value` = 'http://example.org',
			`autoload` = 'yes'
			ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`)";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.org\', \'yes\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$update = "INSERT INTO `wptests_options` SET `option_name` = 'siteurl',
			`option_value` = 'http://example.net',
			`autoload` = 'no'
			ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`),
			                        `autoload` = VALUES(`autoload`)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.net\', \'no\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = excluded."option_value", "autoload" = excluded."autoload"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );
		$this->assertSame( 'http://example.net', $rows[0]->option_value );
		$this->assertSame( 'no', $rows[0]->autoload );
	}

	/**
	 * Tests VALUES upserts without a column list infer table metadata order.
	 */
	public function test_values_upsert_without_column_list_uses_table_metadata_order(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE metadata_order_upsert (
				value TEXT NOT NULL,
				id INTEGER PRIMARY KEY,
				slug TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE metadata_order_upsert (
				id bigint(20) unsigned NOT NULL,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY (id)
			)'
		);

		$insert = "INSERT INTO `metadata_order_upsert` VALUES (1, 'first', 'old')
			ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`),
			                        `value` = VALUES(`value`)";

		$translation = $this->translate_driver_query_data_with_private_method(
			$driver,
			'translate_mysql_on_duplicate_key_update_query',
			$insert
		);

		$this->assertIsArray( $translation );
		$this->assertSame( array( 'id', 'slug', 'value' ), $translation['columns'] );
		$this->assertSame(
			'INSERT INTO "metadata_order_upsert" ("id", "slug", "value") VALUES (1, \'first\', \'old\') ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "value" = excluded."value"',
			$translation['sql']
		);

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "metadata_order_upsert" ("id", "slug", "value") VALUES (1, \'first\', \'old\') ON CONFLICT ("id") DO UPDATE SET "slug" = excluded."slug", "value" = excluded."value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$update = "INSERT INTO `metadata_order_upsert` VALUES (1, 'second', 'new')
			ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`),
			                        `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $update ) );

		$rows = $driver->query( 'SELECT id, slug, value FROM metadata_order_upsert' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'second', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
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
	 * Tests ON DUPLICATE KEY UPDATE supports literal assignment expressions.
	 */
	public function test_options_upsert_literal_assignments_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('siteurl', 'old', 'yes')" );

		$upsert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'inserted', 'ignored')
			ON DUPLICATE KEY UPDATE `option_value` = 'http://example.net',
			                        `autoload` = \"no\"";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'inserted\', \'ignored\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = \'http://example.net\', "autoload" = \'no\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'http://example.net', $rows[0]->option_value );
		$this->assertSame( 'no', $rows[0]->autoload );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE supports INSERT IGNORE, qualified targets, and DEFAULT.
	 */
	public function test_options_upsert_supports_ignore_qualified_assignment_targets_and_default(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('siteurl', 'old', 'no')" );

		$upsert = "INSERT IGNORE INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'inserted', 'off')
			ON DUPLICATE KEY UPDATE `wptests_options`.`option_value` = VALUES(`option_value`),
			                        `wptests`.`wptests_options`.`autoload` = DEFAULT";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'inserted\', \'off\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = excluded."option_value", "autoload" = \'yes\'',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'siteurl'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'inserted', $rows[0]->option_value );
		$this->assertSame( 'yes', $rows[0]->autoload );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE fails closed for unknown assignment qualifiers.
	 */
	public function test_options_upsert_rejects_unknown_assignment_qualifier(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'inserted', 'off')
			ON DUPLICATE KEY UPDATE `other_table`.`option_value` = VALUES(`option_value`)";

		try {
			$driver->query( $upsert );
			$this->fail( 'Expected unsupported qualified upsert assignment to fail closed.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported ON DUPLICATE KEY UPDATE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE decodes text-targeted hex literals.
	 */
	public function test_upsert_update_assignments_decode_hex_literals_for_text_columns(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_hex_values (value TEXT UNIQUE)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_hex_values (
				value varchar(20) NOT NULL,
				UNIQUE KEY value (value)
			)'
		);
		$driver->query( "INSERT INTO wptests_hex_values (value) VALUES ('test')" );

		$upsert = "INSERT INTO wptests_hex_values (value)
			VALUES ('test')
			ON DUPLICATE KEY UPDATE value = 0x61";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_hex_values" ("value") VALUES (\'test\') ON CONFLICT ("value") DO UPDATE SET "value" = \'a\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT value FROM wptests_hex_values' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'a', $rows[0]->value );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE assignment literals use strict target-column coercion.
	 */
	public function test_upsert_update_assignments_use_strict_target_column_coercion(): void {
		$driver = $this->create_driver();
		$this->install_strict_integer_values_table_with_mysql_metadata( $driver );
		$driver->query( 'INSERT INTO wptests_strict_ints (id, int_value) VALUES (1, 1)' );

		$upsert = "INSERT INTO `wptests_strict_ints` (`id`, `int_value`)
			VALUES (1, 2)
			ON DUPLICATE KEY UPDATE `int_value` = '4.0'";

		$this->assertSame( 1, $driver->query( $upsert ) );

		$rows = $driver->query( 'SELECT int_value FROM wptests_strict_ints WHERE id = 1' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '4', $rows[0]->int_value );

		try {
			$driver->query(
				"INSERT INTO `wptests_strict_ints` (`id`, `int_value`)
				VALUES (1, 2)
				ON DUPLICATE KEY UPDATE `int_value` = '12abc'"
			);
			$this->fail( 'Expected invalid upsert assignment value to be rejected in strict SQL mode.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( "Incorrect integer value: '12abc'", $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests non-strict ON DUPLICATE KEY UPDATE NULL assignments still fail for NOT NULL columns.
	 */
	public function test_non_strict_upsert_null_assignment_does_not_coerce_not_null_columns(): void {
		$driver = $this->create_driver();
		$driver->set_sql_mode( '' );

		$driver->query(
			'CREATE TABLE wptests_upsert_not_null (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				size INTEGER DEFAULT 123,
				color TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_upsert_not_null (
				id int(11) NOT NULL,
				name text NOT NULL,
				size int(11) DEFAULT 123,
				color text DEFAULT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO wptests_upsert_not_null (id, name, size, color) VALUES (1, 'A', 10, 'red')" );

		$upsert = "INSERT INTO `wptests_upsert_not_null` (`id`, `name`, `size`, `color`)
			VALUES (1, 'B', 20, 'blue')
			ON DUPLICATE KEY UPDATE `name` = NULL";

		$translation = $this->translate_driver_query_data_with_private_method(
			$driver,
			'translate_mysql_on_duplicate_key_update_query',
			$upsert
		);

		$this->assertSame(
			'INSERT INTO "wptests_upsert_not_null" ("id", "name", "size", "color") VALUES (1, \'B\', 20, \'blue\') ON CONFLICT ("id") DO UPDATE SET "name" = NULL',
			$translation['sql']
		);

		try {
			$driver->query( $upsert );
			$this->fail( 'Expected NOT NULL upsert assignment to fail.' );
		} catch ( PDOException $e ) {
			$this->assertStringContainsString( 'NOT NULL', $e->getMessage() );
		}

		$rows = $driver->query( 'SELECT name, size, color FROM wptests_upsert_not_null WHERE id = 1' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'A', $rows[0]->name );
		$this->assertSame( '10', $rows[0]->size );
		$this->assertSame( 'red', $rows[0]->color );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE supports current-row assignment expressions.
	 */
	public function test_upsert_update_assignments_support_current_row_expressions(): void {
		$driver = $this->create_driver();
		$this->install_strict_integer_values_table_with_mysql_metadata( $driver );
		$driver->query( 'INSERT INTO wptests_strict_ints (id, int_value) VALUES (1, 4)' );

		$upsert = 'INSERT INTO `wptests_strict_ints` (`id`, `int_value`)
			VALUES (1, 2)
			ON DUPLICATE KEY UPDATE `int_value` = `int_value` + 1';

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_strict_ints" ("id", "int_value") VALUES (1, 2) ON CONFLICT ("id") DO UPDATE SET "int_value" = "int_value" + 1',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT int_value FROM wptests_strict_ints WHERE id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '5', $rows[0]->int_value );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE supports VALUES(column) inside simple expressions.
	 */
	public function test_upsert_update_assignments_support_values_inside_simple_expressions(): void {
		$driver = $this->create_driver();
		$this->install_strict_integer_values_table_with_mysql_metadata( $driver );
		$driver->query( 'INSERT INTO wptests_strict_ints (id, int_value) VALUES (1, 4)' );

		$upsert = 'INSERT INTO `wptests_strict_ints` (`id`, `int_value`)
			VALUES (1, 3)
			ON DUPLICATE KEY UPDATE `int_value` = `int_value` + VALUES(`int_value`)';

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_strict_ints" ("id", "int_value") VALUES (1, 3) ON CONFLICT ("id") DO UPDATE SET "int_value" = "int_value" + excluded."int_value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT int_value FROM wptests_strict_ints WHERE id = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->int_value );
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
	 * Tests INSERT ... SELECT ON DUPLICATE KEY UPDATE uses MySQL unique-key metadata.
	 */
	public function test_insert_select_on_duplicate_key_update_uses_metadata_conflict_target(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_plugin_lookup (
				source TEXT NOT NULL,
				external_id TEXT NOT NULL,
				attempts INTEGER NOT NULL,
				payload TEXT NOT NULL,
				UNIQUE (source, external_id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_plugin_lookup (
				source varchar(64) NOT NULL,
				external_id varchar(64) NOT NULL,
				attempts int(11) NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				UNIQUE KEY source_external_id (source, external_id)
			)'
		);

		$insert = "INSERT INTO `wptests_plugin_lookup` (`source`, `external_id`, `attempts`, `payload`)
			SELECT 'feed', 'abc', 1, 'first' FROM DUAL
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_plugin_lookup" ("source", "external_id", "attempts", "payload") SELECT \'feed\', \'abc\', 1, \'first\' ON CONFLICT ("source", "external_id") DO UPDATE SET "attempts" = "attempts" + excluded."attempts", "payload" = excluded."payload"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$update = "INSERT INTO `wptests_plugin_lookup` (`source`, `external_id`, `attempts`, `payload`)
			SELECT 'feed', 'abc', 3, 'second' FROM DUAL
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $update ) );

		$rows = $driver->query( "SELECT attempts, payload FROM wptests_plugin_lookup WHERE source = 'feed' AND external_id = 'abc'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '4', $rows[0]->attempts );
		$this->assertSame( 'second', $rows[0]->payload );
	}

	/**
	 * Tests columnless INSERT ... SELECT upserts infer target columns from MySQL metadata.
	 */
	public function test_columnless_insert_select_on_duplicate_key_update_uses_metadata_columns(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_columnless_plugin_lookup (
				source TEXT NOT NULL,
				external_id TEXT NOT NULL,
				attempts INTEGER NOT NULL,
				payload TEXT NOT NULL,
				UNIQUE (source, external_id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_columnless_plugin_lookup (
				source varchar(64) NOT NULL,
				external_id varchar(64) NOT NULL,
				attempts int(11) NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				UNIQUE KEY source_external_id (source, external_id)
			)'
		);

		$insert = "INSERT INTO `wptests_columnless_plugin_lookup`
			SELECT 'feed', 'abc', 1, 'first' FROM DUAL
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $insert ) );
		$this->assertSame(
			'INSERT INTO "wptests_columnless_plugin_lookup" ("source", "external_id", "attempts", "payload") SELECT \'feed\', \'abc\', 1, \'first\' ON CONFLICT ("source", "external_id") DO UPDATE SET "attempts" = "attempts" + excluded."attempts", "payload" = excluded."payload"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$update = "INSERT INTO `wptests_columnless_plugin_lookup`
			(SELECT 'feed', 'abc', 3, 'second' FROM DUAL)
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			'INSERT INTO "wptests_columnless_plugin_lookup" ("source", "external_id", "attempts", "payload") SELECT \'feed\', \'abc\', 3, \'second\' ON CONFLICT ("source", "external_id") DO UPDATE SET "attempts" = "attempts" + excluded."attempts", "payload" = excluded."payload"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( "SELECT attempts, payload FROM wptests_columnless_plugin_lookup WHERE source = 'feed' AND external_id = 'abc'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '4', $rows[0]->attempts );
		$this->assertSame( 'second', $rows[0]->payload );
	}

	/**
	 * Tests SELECT-sourced upserts with literal AUTO_INCREMENT targets preserve identity semantics.
	 */
	public function test_insert_select_on_duplicate_key_update_with_auto_increment_target_uses_metadata_conflict_target(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`)
			SELECT 7, 'selected' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame( 7, $driver->get_insert_id() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, \'selected\' ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$queries[0]['sql']
		);
		$this->assert_sequence_repair_query( $queries[1], 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->id );
		$this->assertSame( 'selected', $rows[0]->value );

		$update = "INSERT INTO `wptests_identity_upsert` (`id`, `value`)
			SELECT 7, 'updated' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 7, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, \'updated\' ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->id );
		$this->assertSame( 'updated', $rows[0]->value );
	}

	/**
	 * Tests SELECT-sourced upserts allow constant non-key projections for AUTO_INCREMENT targets.
	 */
	public function test_insert_select_on_duplicate_key_update_with_auto_increment_target_allows_constant_value_expression(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`)
			SELECT 7, 1 + 2 FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame( 7, $driver->get_insert_id() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, CAST(1 + 2 AS text) ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$queries[0]['sql']
		);
		$this->assert_sequence_repair_query( $queries[1], 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$update = "INSERT INTO `wptests_identity_upsert` (`id`, `value`)
			SELECT 7, 5 + 6 FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 7, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, CAST(5 + 6 AS text) ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->id );
		$this->assertSame( '11', $rows[0]->value );
	}

	/**
	 * Tests SELECT-sourced upserts still reject expression AUTO_INCREMENT projections.
	 */
	public function test_insert_select_on_duplicate_key_update_with_auto_increment_expression_target_returns_null(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert` (`id`, `value`)
			SELECT 3 + 4, 'selected' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertNull(
			$this->translate_driver_query_data_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$upsert
			)
		);
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests real SELECT-sourced upserts may omit AUTO_INCREMENT for non-AUTO_INCREMENT keys.
	 */
	public function test_insert_select_on_duplicate_key_update_omitting_auto_increment_target_uses_non_auto_unique_key(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_unique_upsert', 'id', 'wptests_identity_unique_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_unique_upsert_table_with_mysql_metadata( $driver );

		$driver->query(
			'CREATE TABLE wptests_identity_unique_upsert_source (
				slug TEXT NOT NULL,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_identity_unique_upsert_source (
				slug varchar(191) NOT NULL,
				value longtext NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_identity_unique_upsert (slug, value) VALUES ('existing', 'old')" );
		$driver->query( "INSERT INTO wptests_identity_unique_upsert_source (slug, value) VALUES ('existing', 'updated'), ('new', 'created')" );

		$upsert = "INSERT INTO `wptests_identity_unique_upsert` (`slug`, `value`)
			SELECT `slug`, `value` FROM `wptests_identity_unique_upsert_source` WHERE 1 = 1
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$translation = $this->translate_driver_query_data_with_private_method(
			$driver,
			'translate_mysql_on_duplicate_key_update_query',
			$upsert
		);

		$this->assertIsArray( $translation );
		$this->assertSame(
			'INSERT INTO "wptests_identity_unique_upsert" ("slug", "value") SELECT "slug", "value" FROM "wptests_identity_unique_upsert_source" WHERE 1 = 1 ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$translation['sql']
		);
		$this->assertNull( $translation['value_rows'] );
		$this->assertNull( $translation['insert_id_value_rows'] );

		$this->assertSame( 2, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "wptests_identity_unique_upsert" ("slug", "value") SELECT "slug", "value" FROM "wptests_identity_unique_upsert_source" WHERE 1 = 1 ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, slug, value FROM wptests_identity_unique_upsert ORDER BY slug' );

		$this->assertCount( 2, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'existing', $rows[0]->slug );
		$this->assertSame( 'updated', $rows[0]->value );
		$this->assertSame( '2', $rows[1]->id );
		$this->assertSame( 'new', $rows[1]->slug );
		$this->assertSame( 'created', $rows[1]->value );
	}

	/**
	 * Tests columnless SELECT-sourced upserts keep AUTO_INCREMENT metadata behavior.
	 */
	public function test_columnless_insert_select_on_duplicate_key_update_with_auto_increment_target_uses_metadata_columns(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$upsert = "INSERT INTO `wptests_identity_upsert`
			SELECT 7, 'selected' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame( 7, $driver->get_insert_id() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 2, $queries );
		$this->assertSame(
			'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, \'selected\' ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
			$queries[0]['sql']
		);
		$this->assert_sequence_repair_query( $queries[1], 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->id );
		$this->assertSame( 'selected', $rows[0]->value );

		$update = "INSERT INTO `wptests_identity_upsert`
			SELECT 7, 'updated' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 7, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_identity_upsert" ("id", "value") SELECT 7, \'updated\' ON CONFLICT ("id") DO UPDATE SET "value" = excluded."value"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$rows = $driver->query( 'SELECT id, value FROM wptests_identity_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]->id );
		$this->assertSame( 'updated', $rows[0]->value );
	}

	/**
	 * Tests columnless real SELECT-sourced upserts still reject AUTO_INCREMENT targets.
	 */
	public function test_columnless_insert_select_on_duplicate_key_update_with_auto_increment_target_rejects_real_source_table(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_identity_upsert', 'id', 'wptests_identity_upsert_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_identity_upsert_table_with_mysql_metadata( $driver );

		$driver->query(
			'CREATE TABLE wptests_identity_upsert_source (
				id INTEGER NOT NULL,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_identity_upsert_source (
				id bigint(20) unsigned NOT NULL,
				value longtext NOT NULL
			)'
		);

		$upsert = "INSERT INTO `wptests_identity_upsert`
			SELECT `id`, `value` FROM `wptests_identity_upsert_source` WHERE 1 = 1
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertNull(
			$this->translate_driver_query_data_with_private_method(
				$driver,
				'translate_mysql_on_duplicate_key_update_query',
				$upsert
			)
		);
		$this->assertSame( 0, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests ambiguous duplicate-key arbiters use the key that actually conflicts.
	 */
	public function test_ambiguous_on_duplicate_key_update_uses_conflicting_unique_key(): void {
		$driver = $this->create_driver();

		$this->install_ambiguous_upsert_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO ambiguous_upsert (id, slug, value) VALUES (1, 'existing', 'old')" );

		$upsert = "INSERT INTO `ambiguous_upsert` (`id`, `slug`, `value`) VALUES (2, 'existing', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "ambiguous_upsert" ("id", "slug", "value") VALUES (2, \'existing\', \'new\') ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, slug, value FROM ambiguous_upsert ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'existing', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
	}

	/**
	 * Tests SELECT-sourced upserts resolve ambiguous targets from literal source rows.
	 */
	public function test_insert_select_on_duplicate_key_update_uses_conflicting_unique_key_for_literal_select(): void {
		$driver = $this->create_driver();

		$this->install_ambiguous_upsert_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO ambiguous_upsert (id, slug, value) VALUES (1, 'existing', 'old')" );

		$upsert = "INSERT INTO `ambiguous_upsert`
			SELECT 2, 'existing', 'new' FROM DUAL
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			'INSERT INTO "ambiguous_upsert" ("id", "slug", "value") SELECT 2, \'existing\', \'new\' ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, slug, value FROM ambiguous_upsert ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'existing', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
	}

	/**
	 * Tests prefix unique duplicate-key arbiters use PostgreSQL expression conflicts.
	 */
	public function test_prefix_unique_on_duplicate_key_update_uses_expression_conflict_target(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE prefix_unique_upsert (
				slug varchar(255) NOT NULL,
				value text NOT NULL,
				UNIQUE KEY slug_prefix (slug(10))
			) DEFAULT CHARACTER SET utf8mb4'
		);
		$driver->query( "INSERT INTO prefix_unique_upsert (`slug`, `value`) VALUES ('existing-slug-one', 'old')" );

		$upsert = "INSERT INTO `prefix_unique_upsert` (`slug`, `value`) VALUES ('existing-slug-two', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert ) );

		$this->assertSame(
			'INSERT INTO "prefix_unique_upsert" ("slug", "value") VALUES (\'existing-slug-two\', \'new\') ON CONFLICT (SUBSTR(CAST("slug" AS text), 1, 10)) DO UPDATE SET "value" = excluded."value"',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
		$rows = $driver->query( 'SELECT slug, value FROM prefix_unique_upsert' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'existing-slug-one', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
	}

	/**
	 * Tests missing column-list upserts infer columns before resolving ambiguous targets.
	 */
	public function test_missing_column_list_upsert_with_ambiguous_conflict_targets_uses_conflicting_key(): void {
		$driver = $this->create_driver();

		$this->install_ambiguous_upsert_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO ambiguous_upsert (id, slug, value) VALUES (1, 'existing', 'old')" );
		$ambiguous_upsert = "INSERT INTO `ambiguous_upsert` VALUES (2, 'existing', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $ambiguous_upsert ) );
		$this->assertSame(
			'INSERT INTO "ambiguous_upsert" ("id", "slug", "value") VALUES (2, \'existing\', \'new\') ON CONFLICT ("slug") DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$this->install_prefix_ambiguous_upsert_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO prefix_ambiguous (id, slug, value) VALUES (1, 'existing-slug-one', 'old')" );
		$prefix_upsert = "INSERT INTO `prefix_ambiguous` VALUES (2, 'existing-slug', 'new')
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $prefix_upsert ) );
		$this->assertSame(
			'INSERT INTO "prefix_ambiguous" ("id", "slug", "value") VALUES (2, \'existing-slug\', \'new\') ON CONFLICT (SUBSTR(CAST("slug" AS text), 1, 10)) DO UPDATE SET "value" = excluded."value"',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, slug, value FROM prefix_ambiguous ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'existing-slug-one', $rows[0]->slug );
		$this->assertSame( 'new', $rows[0]->value );
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
	 * Tests simple single-table UPDATE aliases and qualified assignment targets.
	 */
	public function test_simple_update_with_alias_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_alias (
				id INTEGER PRIMARY KEY,
				value INTEGER NOT NULL
			)'
		);
		$driver->query( 'INSERT INTO wptests_update_alias (id, value) VALUES (1, 4), (2, 8)' );

		$update = 'UPDATE `wptests_update_alias` AS ua SET ua.value = ua.value + 1 WHERE ua.id = 1';

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_alias" AS "ua" SET "value" = ua.value + 1 WHERE (ua.id = 1) AND ("ua"."value" IS DISTINCT FROM (ua.value + 1))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, value FROM wptests_update_alias ORDER BY id' );
		$this->assertSame( '5', $rows[0]->value );
		$this->assertSame( '8', $rows[1]->value );
	}

	/**
	 * Tests MySQL inner joined UPDATE statements translate through PostgreSQL UPDATE FROM.
	 */
	public function test_inner_join_update_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_joined (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_joined_meta (
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_joined (id, status) VALUES (1, 'draft'), (2, 'draft')" );
		$driver->query( "INSERT INTO wptests_update_joined_meta (post_id, meta_key, meta_value) VALUES (1, '_status', 'publish'), (2, '_other', 'private')" );

		$update = "UPDATE wptests_update_joined AS p JOIN wptests_update_joined_meta AS pm ON p.id = pm.post_id SET p.status = pm.meta_value WHERE pm.meta_key = '_status'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 0, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_joined" AS "p" SET "status" = pm.meta_value FROM "wptests_update_joined_meta" AS "pm" WHERE (p.id = pm.post_id) AND (pm.meta_key = \'_status\') AND ("p"."status" IS DISTINCT FROM (pm.meta_value))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_update_joined ORDER BY id' );
		$this->assertSame( 'publish', $rows[0]->status );
		$this->assertSame( 'draft', $rows[1]->status );
	}

	/**
	 * Tests MySQL inner joined UPDATE statements support derived-table sources.
	 */
	public function test_inner_join_update_with_derived_source_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_joined_derived (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_joined_derived_meta (
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_joined_derived (id, status) VALUES (1, 'draft'), (2, 'draft')" );
		$driver->query( "INSERT INTO wptests_update_joined_derived_meta (post_id, meta_key, meta_value) VALUES (1, '_status', 'publish'), (2, '_other', 'private')" );

		$update = "UPDATE wptests_update_joined_derived AS p
			JOIN (
				SELECT post_id, meta_value
				FROM wptests_update_joined_derived_meta
				WHERE meta_key = '_status'
			) AS src ON p.id = src.post_id
			SET p.status = src.meta_value
			WHERE p.id IN (1, 2)";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 0, $driver->query( $update ) );

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertStringContainsString( 'FROM (SELECT post_id, meta_value FROM wptests_update_joined_derived_meta WHERE meta_key = \'_status\') AS "src"', $sql );
		$this->assertStringContainsString( '(p.id = src.post_id)', $sql );
		$this->assertStringContainsString( '("p"."status" IS DISTINCT FROM (src.meta_value))', $sql );

		$rows = $driver->query( 'SELECT id, status FROM wptests_update_joined_derived ORDER BY id' );
		$this->assertSame( 'publish', $rows[0]->status );
		$this->assertSame( 'draft', $rows[1]->status );
	}

	/**
	 * Tests MySQL inner joined UPDATE ... USING statements translate to PostgreSQL predicates.
	 */
	public function test_inner_join_update_using_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_joined_using (
				post_id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_joined_using_meta (
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_joined_using (post_id, status) VALUES (1, 'draft'), (2, 'draft')" );
		$driver->query( "INSERT INTO wptests_update_joined_using_meta (post_id, meta_key, meta_value) VALUES (1, '_status', 'publish'), (2, '_other', 'private')" );

		$update = "UPDATE wptests_update_joined_using AS p INNER JOIN wptests_update_joined_using_meta AS pm USING (post_id) SET p.status = pm.meta_value WHERE pm.meta_key = '_status'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 0, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_joined_using" AS "p" SET "status" = pm.meta_value FROM "wptests_update_joined_using_meta" AS "pm" WHERE (p.post_id = pm.post_id) AND (pm.meta_key = \'_status\') AND ("p"."status" IS DISTINCT FROM (pm.meta_value))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT post_id, status FROM wptests_update_joined_using ORDER BY post_id' );
		$this->assertSame( 'publish', $rows[0]->status );
		$this->assertSame( 'draft', $rows[1]->status );
	}

	/**
	 * Tests unsupported multi-target UPDATE statements fail before backend execution.
	 */
	public function test_multi_target_update_fails_closed_before_backend_execution(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_source (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_target (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);

		try {
			$driver->query( 'UPDATE wptests_update_source AS s, wptests_update_target AS t SET t.value = s.value WHERE t.id = s.id' );
			$this->fail( 'Expected unsupported UPDATE statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported UPDATE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests unsupported joined UPDATE variants fail before backend execution.
	 */
	public function test_unsupported_joined_update_shapes_fail_closed_before_backend_execution(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_right_source (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_right_target (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);

		try {
			$driver->query( 'UPDATE wptests_update_right_source AS s RIGHT JOIN wptests_update_right_target AS t ON t.id = s.id SET s.value = t.value' );
			$this->fail( 'Expected unsupported UPDATE statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported UPDATE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests MySQL comma/join UPDATE statements translate through PostgreSQL UPDATE FROM.
	 */
	public function test_comma_join_update_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_multi_source (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_multi_source_meta (
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_multi_source_terms (
				post_id INTEGER NOT NULL,
				term TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_multi_source (id, status) VALUES (1, 'draft'), (2, 'draft'), (3, 'draft')" );
		$driver->query( "INSERT INTO wptests_update_multi_source_meta (post_id, meta_key, meta_value) VALUES (1, '_status', 'publish'), (2, '_status', 'private'), (3, '_other', 'ignore')" );
		$driver->query( "INSERT INTO wptests_update_multi_source_terms (post_id, term) VALUES (1, 'publish'), (2, 'skip'), (3, 'publish')" );

		$update = "UPDATE wptests_update_multi_source AS p, wptests_update_multi_source_meta AS pm
			JOIN wptests_update_multi_source_terms AS tt ON tt.post_id = p.id
			SET p.status = pm.meta_value
			WHERE pm.post_id = p.id
			AND pm.meta_key = '_status'
			AND tt.term = 'publish'";

		$this->assertSame( 1, $driver->query( $update ) );
		$this->assertSame( 0, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_multi_source" AS "p" SET "status" = pm.meta_value FROM "wptests_update_multi_source_meta" AS "pm", "wptests_update_multi_source_terms" AS "tt" WHERE (tt.post_id = p.id) AND (pm.post_id = p.id AND pm.meta_key = \'_status\' AND tt.term = \'publish\') AND ("p"."status" IS DISTINCT FROM (pm.meta_value))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_update_multi_source ORDER BY id' );
		$this->assertSame( 'publish', $rows[0]->status );
		$this->assertSame( 'draft', $rows[1]->status );
		$this->assertSame( 'draft', $rows[2]->status );
	}

	/**
	 * Tests MySQL LEFT JOIN UPDATE statements preserve unmatched source rows.
	 */
	public function test_left_join_update_is_translated_through_derived_ctid_source(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_left_joined (
				id INTEGER PRIMARY KEY,
				author_id INTEGER NOT NULL,
				status TEXT NOT NULL
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_update_left_joined_meta (
				post_id INTEGER NOT NULL,
				meta_key TEXT NOT NULL,
				meta_value TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_left_joined (id, author_id, status) VALUES (1, 10, 'draft'), (2, 20, 'draft'), (3, 30, 'publish')" );
		$driver->query( "INSERT INTO wptests_update_left_joined_meta (post_id, meta_key, meta_value) VALUES (10, '_status', 'scheduled'), (10, '_other', 'ignored')" );

		$update = "UPDATE wptests_update_left_joined AS p
			LEFT JOIN wptests_update_left_joined_meta AS pm
				ON pm.post_id = p.author_id AND pm.meta_key = '_status'
			SET p.status = IFNULL(pm.meta_value, 'orphan')
			WHERE p.status = 'draft'";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_update_query',
			$update
		);

		$this->assertSame(
			'UPDATE "wptests_update_left_joined" AS "p" SET "status" = "mysql_update_values"."mysql_update_value_0" FROM (SELECT "p".ctid AS "mysql_update_target_ctid", COALESCE(pm.meta_value, \'orphan\') AS "mysql_update_value_0" FROM wptests_update_left_joined AS p LEFT JOIN wptests_update_left_joined_meta AS pm ON pm.post_id = p.author_id AND pm.meta_key = \'_status\' WHERE p.status = \'draft\') AS "mysql_update_values" WHERE ("p".ctid = "mysql_update_values"."mysql_update_target_ctid") AND ("p"."status" IS DISTINCT FROM ("mysql_update_values"."mysql_update_value_0"))',
			$sql
		);
	}

	/**
	 * Tests bounded UPDATE ORDER BY/LIMIT forms translate through PostgreSQL ctid.
	 */
	public function test_simple_update_order_by_limit_translates_to_ctid_subquery(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_update_query',
			'UPDATE wptests_update_limited SET `value` = 9 WHERE id > 0 ORDER BY id DESC LIMIT 1'
		);

		$this->assertSame(
			'UPDATE "wptests_update_limited" SET "value" = 9 WHERE (ctid IN (SELECT ctid FROM "wptests_update_limited" WHERE id > 0 ORDER BY id DESC LIMIT 1)) AND ("value" IS DISTINCT FROM (9))',
			$sql
		);

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_update_query',
			'UPDATE wptests_update_limited SET `value` = 7 WHERE id > 0 ORDER BY id DESC LIMIT 1, 2'
		);

		$this->assertSame(
			'UPDATE "wptests_update_limited" SET "value" = 7 WHERE (ctid IN (SELECT ctid FROM "wptests_update_limited" WHERE id > 0 ORDER BY id DESC LIMIT 2 OFFSET 1)) AND ("value" IS DISTINCT FROM (7))',
			$sql
		);
	}

	/**
	 * Tests simple UPDATE ORDER BY without LIMIT updates the same matched row set.
	 */
	public function test_simple_update_order_by_without_limit_omits_ordering(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_ordered (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_ordered (id, status) VALUES (1, 'draft'), (2, 'publish'), (3, 'draft')" );

		$update = "UPDATE `wptests_update_ordered` SET `status` = 'archived' WHERE `status` = 'draft' ORDER BY `id` DESC";

		$this->assertSame( 2, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_ordered" SET "status" = \'archived\' WHERE ("status" = \'draft\') AND ("status" IS DISTINCT FROM (\'archived\'))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_update_ordered ORDER BY id' );
		$this->assertSame( 'archived', $rows[0]->status );
		$this->assertSame( 'publish', $rows[1]->status );
		$this->assertSame( 'archived', $rows[2]->status );
	}

	/**
	 * Tests simple UPDATE ignores unsupported ORDER BY expressions without LIMIT.
	 */
	public function test_simple_update_expression_order_by_without_limit_omits_ordering(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_update_order_expression (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_update_order_expression (id, status) VALUES (1, 'draft'), (2, 'publish'), (3, 'draft')" );

		$update = "UPDATE wptests_update_order_expression SET `status` = 'archived' WHERE `status` = 'draft' ORDER BY LENGTH(`status`), `id` + 0 DESC";

		$this->assertSame( 2, $driver->query( $update ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'UPDATE "wptests_update_order_expression" SET "status" = \'archived\' WHERE ("status" = \'draft\') AND ("status" IS DISTINCT FROM (\'archived\'))',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_update_order_expression ORDER BY id' );
		$this->assertSame( 'archived', $rows[0]->status );
		$this->assertSame( 'publish', $rows[1]->status );
		$this->assertSame( 'archived', $rows[2]->status );
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
		$driver->set_sql_mode( '' );
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
		$driver->set_sql_mode( '' );
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
	 * Tests simple DELETE without WHERE is translated instead of passed through raw.
	 */
	public function test_simple_delete_without_where_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_delete_all (id INTEGER PRIMARY KEY, value TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_delete_all (id, value) VALUES (1, 'one'), (2, 'two')" );

		$delete = 'DELETE FROM wptests_delete_all';

		$this->assertSame( 2, $driver->query( $delete ) );
		$this->assertSame( 'DELETE FROM "wptests_delete_all"', $this->get_last_single_postgresql_sql( $driver ) );

		$rows = $driver->query( 'SELECT id FROM wptests_delete_all' );
		$this->assertSame( array(), $rows );
	}

	/**
	 * Tests simple single-table DELETE aliases are translated to PostgreSQL aliases.
	 */
	public function test_simple_delete_with_alias_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_delete_alias (id INTEGER PRIMARY KEY, value TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_delete_alias (id, value) VALUES (1, 'one'), (2, 'two')" );

		$delete = 'DELETE FROM `wptests_delete_alias` AS d WHERE d.id = 1';

		$this->assertSame( 1, $driver->query( $delete ) );
		$this->assertSame(
			'DELETE FROM "wptests_delete_alias" AS "d" WHERE d.id = 1',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT id, value FROM wptests_delete_alias ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->id );
	}

	/**
	 * Tests simple DELETE ORDER BY without LIMIT deletes the same matched row set.
	 */
	public function test_simple_delete_order_by_without_limit_omits_ordering(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_delete_ordered (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_delete_ordered (id, status) VALUES (1, 'stale'), (2, 'keep'), (3, 'stale')" );

		$delete = "DELETE FROM `wptests_delete_ordered` WHERE `status` = 'stale' ORDER BY `id` DESC";

		$this->assertSame( 2, $driver->query( $delete ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DELETE FROM "wptests_delete_ordered" WHERE "status" = \'stale\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_delete_ordered ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->id );
		$this->assertSame( 'keep', $rows[0]->status );
	}

	/**
	 * Tests simple DELETE ignores unsupported ORDER BY expressions without LIMIT.
	 */
	public function test_simple_delete_expression_order_by_without_limit_omits_ordering(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_delete_order_expression (
				id INTEGER PRIMARY KEY,
				status TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_delete_order_expression (id, status) VALUES (1, 'stale'), (2, 'keep'), (3, 'stale')" );

		$delete = "DELETE FROM wptests_delete_order_expression WHERE `status` = 'stale' ORDER BY LENGTH(`status`), `id` + 0 DESC";

		$this->assertSame( 2, $driver->query( $delete ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'DELETE FROM "wptests_delete_order_expression" WHERE "status" = \'stale\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( 'SELECT id, status FROM wptests_delete_order_expression ORDER BY id' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2', $rows[0]->id );
		$this->assertSame( 'keep', $rows[0]->status );
	}

	/**
	 * Tests bounded DELETE ORDER BY/LIMIT forms translate through PostgreSQL ctid.
	 */
	public function test_simple_delete_order_by_limit_translates_to_ctid_subquery(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_delete_query',
			"DELETE FROM wptests_delete_limited AS d WHERE d.value = 'stale' ORDER BY d.id ASC LIMIT 1"
		);

		$this->assertSame(
			'DELETE FROM "wptests_delete_limited" AS "d" WHERE "d".ctid IN (SELECT "d".ctid FROM "wptests_delete_limited" AS "d" WHERE d.value = \'stale\' ORDER BY d.id ASC LIMIT 1)',
			$sql
		);

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_delete_query',
			"DELETE FROM wptests_delete_limited AS d WHERE d.value = 'stale' ORDER BY d.id ASC LIMIT 1, 2"
		);

		$this->assertSame(
			'DELETE FROM "wptests_delete_limited" AS "d" WHERE "d".ctid IN (SELECT "d".ctid FROM "wptests_delete_limited" AS "d" WHERE d.value = \'stale\' ORDER BY d.id ASC LIMIT 2 OFFSET 1)',
			$sql
		);
	}

	/**
	 * Tests bounded DELETE with multi-column ORDER BY and offset/count LIMIT.
	 */
	public function test_simple_delete_multi_column_order_by_limit_offset_translates_to_ctid_subquery(): void {
		$driver = $this->create_driver();

		$delete = "DELETE FROM wptests_delete_order_multi
			WHERE state = 'stale'
			ORDER BY priority ASC, id DESC
			LIMIT 1, 2";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_simple_mysql_delete_query',
			$delete
		);

		$this->assertSame(
			'DELETE FROM "wptests_delete_order_multi" WHERE ctid IN (SELECT ctid FROM "wptests_delete_order_multi" WHERE state = \'stale\' ORDER BY priority ASC, id DESC LIMIT 2 OFFSET 1)',
			$sql
		);
	}

	/**
	 * Tests MySQL multi-target DELETE statements translate to PostgreSQL writable CTEs.
	 */
	public function test_mysql_multi_target_delete_is_translated_to_writable_ctes(): void {
		$driver = $this->create_driver();

		$delete = "DELETE p, c
			FROM wptests_delete_multi_parent AS p
			JOIN wptests_delete_multi_child AS c ON c.parent_id = p.id
			WHERE p.status = 'stale'";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_multi_target_delete_query',
			$delete
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'WITH mysql_delete_rows AS MATERIALIZED', $sql );
		$this->assertStringContainsString( 'SELECT "p".ctid AS "mysql_delete_target_0_ctid", "c".ctid AS "mysql_delete_target_1_ctid"', $sql );
		$this->assertStringContainsString( 'DELETE FROM "wptests_delete_multi_parent" AS "p"', $sql );
		$this->assertStringContainsString( 'DELETE FROM "wptests_delete_multi_child" AS "c"', $sql );
		$this->assertStringContainsString( 'SELECT (SELECT COUNT(*) FROM mysql_delete_target_0) + (SELECT COUNT(*) FROM mysql_delete_target_1) AS affected_rows', $sql );
	}

	/**
	 * Tests MySQL multi-target DELETE USING statements share the writable CTE path.
	 */
	public function test_mysql_multi_target_delete_using_is_translated_to_writable_ctes(): void {
		$driver = $this->create_driver();

		$delete = "DELETE FROM p, c
			USING wptests_delete_using_parent AS p
			JOIN wptests_delete_using_child AS c ON c.parent_id = p.id
			WHERE p.status = 'stale'";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_multi_target_delete_query',
			$delete
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'WITH mysql_delete_rows AS MATERIALIZED', $sql );
		$this->assertStringContainsString( 'SELECT "p".ctid AS "mysql_delete_target_0_ctid", "c".ctid AS "mysql_delete_target_1_ctid"', $sql );
		$this->assertStringContainsString( 'FROM wptests_delete_using_parent AS p JOIN wptests_delete_using_child AS c ON c.parent_id = p.id', $sql );
		$this->assertStringContainsString( 'DELETE FROM "wptests_delete_using_parent" AS "p"', $sql );
		$this->assertStringContainsString( 'DELETE FROM "wptests_delete_using_child" AS "c"', $sql );
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
	 * Tests unsupported DELETE shapes fail before backend execution.
	 */
	public function test_unsupported_delete_shapes_fail_closed_before_backend(): void {
		$queries = array(
			"DELETE d FROM wptests_delete d
				JOIN wptests_related r ON r.id = d.related_id
				WHERE d.status = 'old'
				ORDER BY d.id LIMIT 1",
			"DELETE d FROM wptests_delete d
				JOIN wptests_related r ON r.id = d.related_id
				WHERE d.name REGEXP '^x'",
			"DELETE d, r FROM wptests_delete d
				JOIN wptests_related r ON r.id = d.related_id
				WHERE d.status = 'old'
				ORDER BY d.id LIMIT 1",
			"DELETE d FROM other_db.wptests_delete d
				JOIN wptests_related r ON r.id = d.related_id
				WHERE d.status = 'old'",
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported DELETE statement.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported DELETE statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
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
	 * Tests common MySQL runtime functions from the SQLite compatibility layer are translated.
	 */
	public function test_common_mysql_runtime_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT MD5('abc') AS md5_hash,
			LEFT('Lorem ipsum', 5) AS left_part,
			UCASE('abc') AS upper_part,
			LCASE('ABC') AS lower_part,
			ISNULL(NULL) AS is_null_value,
			IF(1, 'yes', 'no') AS if_numeric,
			IF(1 = 0, 'yes', 'no') AS if_predicate,
			IFNULL(NULL, 'fallback') AS ifnull_value,
			CONCAT('wp', '_', 'db') AS concat_value,
			CHAR_LENGTH('hello') AS char_length_value,
			CHARACTER_LENGTH('hello') AS character_length_value,
			SUBSTRING('abcdef', 2, 3) AS substring_value,
			SUBSTRING('abcdef' FROM 2 FOR 3) AS substring_from_value,
			SUBSTR('abcdef', 4) AS substr_value,
			MID('abcdef', 2, 2) AS mid_value,
			REPLACE('banana', 'na', 'NA') AS replace_value,
			LEAST(3, 9, 4) AS least_value,
			GREATEST(3, 9, 4) AS greatest_value,
			LOG(8) AS natural_log_value,
			LOG(2, 8) AS based_log_value,
			DATEDIFF('2024-01-05', '2024-01-02') AS day_diff,
			LENGTH('hello') AS byte_length,
			LOCATE('or', 'WordPress') AS locate_value,
			LOCATE('r', 'WordPress', 4) AS locate_with_position,
			HEX('Az') AS hex_value,
			UNHEX('417a') AS unhex_value,
			TO_BASE64('wp') AS base64_value,
			FROM_BASE64('d3A=') AS base64_decoded,
			INET_ATON('127.0.0.1') AS inet_number,
			INET_NTOA(2130706433) AS inet_address,
			FROM_UNIXTIME(0) AS epoch_datetime,
			FROM_UNIXTIME(0, '%Y') AS epoch_year,
			UNIX_TIMESTAMP('1970-01-02 00:00:00') AS epoch_seconds";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			$select
		);

		$this->assertStringContainsString( "MD5(CAST('abc' AS text)) AS md5_hash", $sql );
		$this->assertStringContainsString( "LEFT(CAST('Lorem ipsum' AS text), CAST(5 AS integer)) AS left_part", $sql );
		$this->assertStringContainsString( "UPPER(CAST('abc' AS text)) AS upper_part", $sql );
		$this->assertStringContainsString( "LOWER(CAST('ABC' AS text)) AS lower_part", $sql );
		$this->assertStringContainsString( 'CASE WHEN NULL IS NULL THEN 1 ELSE 0 END AS is_null_value', $sql );
		$this->assertStringContainsString( "THEN 'yes' ELSE 'no' END AS if_numeric", $sql );
		$this->assertStringContainsString( "CASE WHEN (1 = 0) THEN 'yes' ELSE 'no' END AS if_predicate", $sql );
		$this->assertStringContainsString( "COALESCE(NULL, 'fallback') AS ifnull_value", $sql );
		$this->assertStringContainsString( "(CAST('wp' AS text) || CAST('_' AS text) || CAST('db' AS text)) AS concat_value", $sql );
		$this->assertStringContainsString( "CHAR_LENGTH(CAST('hello' AS text)) AS char_length_value", $sql );
		$this->assertStringContainsString( "CHAR_LENGTH(CAST('hello' AS text)) AS character_length_value", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST('abcdef' AS text) FROM CASE WHEN CAST(2 AS integer) > 0 THEN CAST(2 AS integer) WHEN CAST(2 AS integer) < 0 THEN CHAR_LENGTH(CAST('abcdef' AS text)) + CAST(2 AS integer) + 1 ELSE 0 END FOR CAST(3 AS integer)) END AS substring_value", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST('abcdef' AS text) FROM CASE WHEN CAST(2 AS integer) > 0 THEN CAST(2 AS integer) WHEN CAST(2 AS integer) < 0 THEN CHAR_LENGTH(CAST('abcdef' AS text)) + CAST(2 AS integer) + 1 ELSE 0 END FOR CAST(3 AS integer)) END AS substring_from_value", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST('abcdef' AS text) FROM CASE WHEN CAST(4 AS integer) > 0 THEN CAST(4 AS integer) WHEN CAST(4 AS integer) < 0 THEN CHAR_LENGTH(CAST('abcdef' AS text)) + CAST(4 AS integer) + 1 ELSE 0 END) END AS substr_value", $sql );
		$this->assertStringContainsString( "SUBSTRING(CAST('abcdef' AS text) FROM CASE WHEN CAST(2 AS integer) > 0 THEN CAST(2 AS integer) WHEN CAST(2 AS integer) < 0 THEN CHAR_LENGTH(CAST('abcdef' AS text)) + CAST(2 AS integer) + 1 ELSE 0 END FOR CAST(2 AS integer)) END AS mid_value", $sql );
		$this->assertStringContainsString( "REPLACE(CAST('banana' AS text), CAST('na' AS text), CAST('NA' AS text)) AS replace_value", $sql );
		$this->assertStringContainsString( 'CASE WHEN 3 IS NULL OR 9 IS NULL OR 4 IS NULL THEN NULL ELSE LEAST(3, 9, 4) END AS least_value', $sql );
		$this->assertStringContainsString( 'CASE WHEN 3 IS NULL OR 9 IS NULL OR 4 IS NULL THEN NULL ELSE GREATEST(3, 9, 4) END AS greatest_value', $sql );
		$this->assertStringContainsString( 'CASE WHEN CAST(8 AS double precision) IS NULL OR CAST(8 AS double precision) <= 0 THEN NULL ELSE LN(CAST(8 AS double precision)) END AS natural_log_value', $sql );
		$this->assertStringContainsString( 'CASE WHEN CAST(2 AS double precision) IS NULL OR CAST(8 AS double precision) IS NULL OR CAST(2 AS double precision) <= 1 OR CAST(8 AS double precision) <= 0 THEN NULL ELSE LN(CAST(8 AS double precision)) / LN(CAST(2 AS double precision)) END AS based_log_value', $sql );
		$this->assertStringContainsString( "CAST((CAST('2024-01-05' AS date) - CAST('2024-01-02' AS date)) AS integer) AS day_diff", $sql );
		$this->assertStringContainsString( "ELSE OCTET_LENGTH(CONVERT_TO(CAST('hello' AS text), 'UTF8')) END AS byte_length", $sql );
		$this->assertStringContainsString( "STRPOS(CAST('WordPress' AS text), CAST('or' AS text)) AS locate_value", $sql );
		$this->assertStringContainsString( "STRPOS(SUBSTRING(CAST('WordPress' AS text) FROM CAST(4 AS integer)), CAST('r' AS text)) + CAST(4 AS integer) - 1 END AS locate_with_position", $sql );
		$this->assertStringContainsString( "UPPER(ENCODE(CONVERT_TO(CAST('Az' AS text), 'UTF8'), 'hex')) AS hex_value", $sql );
		$this->assertStringContainsString( "CONVERT_FROM(DECODE(CAST('417a' AS text), 'hex'), 'UTF8') AS unhex_value", $sql );
		$this->assertStringContainsString( "ENCODE(CONVERT_TO(CAST('wp' AS text), 'UTF8'), 'base64') AS base64_value", $sql );
		$this->assertStringContainsString( "CONVERT_FROM(DECODE(CAST('d3A=' AS text), 'base64'), 'UTF8') AS base64_decoded", $sql );
		$this->assertStringContainsString( "SPLIT_PART(CAST('127.0.0.1' AS text), '.', 1)", $sql );
		$this->assertStringContainsString( '((CAST(2130706433 AS bigint) >> 24) & 255)::text', $sql );
		$this->assertStringContainsString( "TO_CHAR(TO_TIMESTAMP(CAST(0 AS double precision)) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS epoch_datetime", $sql );
		$this->assertStringContainsString( "TO_TIMESTAMP(CAST(0 AS double precision)) AT TIME ZONE 'UTC'", $sql );
		$this->assertStringContainsString( "'YYYY'", $sql );
		$this->assertStringContainsString( 'CAST(FLOOR(EXTRACT(EPOCH FROM CAST(CASE WHEN CAST(\'1970-01-02 00:00:00\' AS text)', $sql );
		$this->assertStringNotContainsString( 'UNIX_TIMESTAMP', $sql );
		$this->assertStringNotContainsString( 'FROM_UNIXTIME', $sql );
		$this->assertStringNotContainsString( 'INET_ATON', $sql );
		$this->assertStringNotContainsString( 'INET_NTOA', $sql );
		$this->assertStringNotContainsString( 'FROM_BASE64', $sql );
		$this->assertStringNotContainsString( 'TO_BASE64', $sql );
		$this->assertStringNotContainsString( 'IFNULL', $sql );
		$this->assertStringNotContainsString( 'CONCAT(', $sql );
	}

	/**
	 * Tests SQLite UDF-style REGEXP(pattern, value) runtime calls are translated.
	 */
	public function test_regexp_runtime_function_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT
				REGEXP('^rss_.+$', 'RSS_123') AS case_insensitive_match,
				REGEXP('^rss_.+$', 'feed_123') AS no_match,
				REGEXP(NULL, 'RSS_123') AS null_pattern,
				REGEXP('^rss', NULL) AS null_value"
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString(
			"CASE WHEN CAST('^rss_.+$' AS text) IS NULL OR CAST('RSS_123' AS text) IS NULL THEN NULL WHEN CAST('RSS_123' AS text) ~* CAST('^rss_.+$' AS text) THEN 1 ELSE 0 END AS case_insensitive_match",
			$sql
		);
		$this->assertStringContainsString(
			"CASE WHEN CAST('^rss_.+$' AS text) IS NULL OR CAST('feed_123' AS text) IS NULL THEN NULL WHEN CAST('feed_123' AS text) ~* CAST('^rss_.+$' AS text) THEN 1 ELSE 0 END AS no_match",
			$sql
		);
		$this->assertStringContainsString(
			"CASE WHEN CAST(NULL AS text) IS NULL OR CAST('RSS_123' AS text) IS NULL THEN NULL WHEN CAST('RSS_123' AS text) ~* CAST(NULL AS text) THEN 1 ELSE 0 END AS null_pattern",
			$sql
		);
		$this->assertStringContainsString(
			"CASE WHEN CAST('^rss' AS text) IS NULL OR CAST(NULL AS text) IS NULL THEN NULL WHEN CAST(NULL AS text) ~* CAST('^rss' AS text) THEN 1 ELSE 0 END AS null_value",
			$sql
		);
		$this->assertStringNotContainsString( 'REGEXP(', $sql );
	}

	/**
	 * Tests nested MySQL base64 runtime functions from the SQLite parity suite are translated.
	 */
	public function test_nested_base64_runtime_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT
			TO_BASE64(FROM_BASE64('dGVzdA==')) AS encoded_round_trip,
			FROM_BASE64(TO_BASE64('binary')) AS decoded_round_trip,
			COALESCE(FROM_BASE64(''), 'fallback') AS empty_decoded";

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			$select
		);

		$this->assertStringContainsString(
			"ENCODE(CONVERT_TO(CAST(CONVERT_FROM(DECODE(CAST('dGVzdA==' AS text), 'base64'), 'UTF8') AS text), 'UTF8'), 'base64') AS encoded_round_trip",
			$sql
		);
		$this->assertStringContainsString(
			"CONVERT_FROM(DECODE(CAST(ENCODE(CONVERT_TO(CAST('binary' AS text), 'UTF8'), 'base64') AS text), 'base64'), 'UTF8') AS decoded_round_trip",
			$sql
		);
		$this->assertStringContainsString(
			"COALESCE (CONVERT_FROM(DECODE(CAST('' AS text), 'base64'), 'UTF8'), 'fallback') AS empty_decoded",
			$sql
		);
		$this->assertStringNotContainsString( 'FROM_BASE64', $sql );
		$this->assertStringNotContainsString( 'TO_BASE64', $sql );
	}

	/**
	 * Tests NULL-only MySQL base64 runtime calls still trigger PostgreSQL translation.
	 */
	public function test_null_base64_runtime_functions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			'SELECT FROM_BASE64(NULL) AS decoded_null, TO_BASE64(NULL) AS encoded_null'
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( "CONVERT_FROM(DECODE(CAST(NULL AS text), 'base64'), 'UTF8') AS decoded_null", $sql );
		$this->assertStringContainsString( "ENCODE(CONVERT_TO(CAST(NULL AS text), 'UTF8'), 'base64') AS encoded_null", $sql );
		$this->assertStringNotContainsString( 'FROM_BASE64', $sql );
		$this->assertStringNotContainsString( 'TO_BASE64', $sql );
	}

	/**
	 * Tests common MySQL runtime functions trigger rewrite without literal arguments.
	 */
	public function test_common_mysql_runtime_functions_with_column_arguments_trigger_postgresql_rewrite(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			'SELECT IFNULL(primary_value, fallback_value) AS selected_value, CONCAT(prefix, suffix) AS joined_value, CHAR_LENGTH(display_name) AS name_length, LENGTH(display_name) AS byte_length FROM runtime_names'
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'COALESCE(primary_value, fallback_value) AS selected_value', $sql );
		$this->assertStringContainsString( '(CAST(prefix AS text) || CAST(suffix AS text)) AS joined_value', $sql );
		$this->assertStringContainsString( 'CHAR_LENGTH(CAST(display_name AS text)) AS name_length', $sql );
		$this->assertStringContainsString( "ELSE OCTET_LENGTH(CONVERT_TO(CAST(display_name AS text), 'UTF8')) END AS byte_length", $sql );
		$this->assertStringNotContainsString( 'IFNULL', $sql );
		$this->assertStringNotContainsString( 'CONCAT(', $sql );
	}

	/**
	 * Tests MySQL LENGTH() counts bytes for UTF-8 text.
	 */
	public function test_length_runtime_function_counts_utf8_bytes_for_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT LENGTH(UNHEX('c3a9')) AS utf8_byte_length, LENGTH(UNHEX('ff')) AS binary_byte_length"
		);

		$this->assertSame(
			"SELECT OCTET_LENGTH(DECODE(CAST('c3a9' AS text), 'hex')) AS utf8_byte_length, OCTET_LENGTH(DECODE(CAST('ff' AS text), 'hex')) AS binary_byte_length",
			$sql
		);
	}

	/**
	 * Tests MySQL LENGTH() counts text bytes while CHAR_LENGTH() counts characters.
	 */
	public function test_length_runtime_function_counts_text_utf8_bytes_when_executed(): void {
		$driver  = $this->create_driver_with_postgresql_text_runtime_functions();
		$literal = $this->quote_mysql_string_literal_for_test( "\xC3\xA9" );

		$result = $driver->query(
			sprintf(
				'SELECT LENGTH(%1$s) AS byte_length, CHAR_LENGTH(%1$s) AS char_length, CHARACTER_LENGTH(%1$s) AS character_length',
				$literal
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( '2', $result[0]->byte_length );
		$this->assertSame( '1', $result[0]->char_length );
		$this->assertSame( '1', $result[0]->character_length );

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertStringContainsString( "ELSE OCTET_LENGTH(CONVERT_TO(CAST($literal AS text), 'UTF8')) END AS byte_length", $sql );
		$this->assertStringContainsString( "CHAR_LENGTH(CAST($literal AS text)) AS char_length", $sql );
		$this->assertStringContainsString( "CHAR_LENGTH(CAST($literal AS text)) AS character_length", $sql );
	}

	/**
	 * Tests MySQL LENGTH() counts decoded PostgreSQL-safe text envelope bytes.
	 */
	public function test_length_runtime_function_counts_postgresql_text_envelope_bytes_when_executed(): void {
		$driver     = $this->create_driver_with_postgresql_quote_translation_and_text_runtime_functions();
		$connection = $driver->get_connection();

		$driver->query( 'CREATE TABLE wptests_length_text_envelope (value TEXT NOT NULL)' );
		$connection->query( 'INSERT INTO wptests_length_text_envelope (value) VALUES (' . $connection->quote( "a\0\xC3\xA9" ) . ')' );

		$stored_rows = $connection->query( 'SELECT value FROM wptests_length_text_envelope' )->fetchAll( PDO::FETCH_OBJ );
		$this->assertCount( 1, $stored_rows );
		$this->assertStringContainsString( 'WP_MYSQL_TEXT_V1:', $stored_rows[0]->value );

		$result = $driver->query( 'SELECT LENGTH(value) AS byte_length FROM wptests_length_text_envelope' );

		$this->assertCount( 1, $result );
		$this->assertSame( '4', (string) $result[0]->byte_length );

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertStringContainsString( 'STRPOS(SUBSTR(CAST(value AS text),', $sql );
		$this->assertStringContainsString( "ELSE OCTET_LENGTH(CONVERT_TO(CAST(value AS text), 'UTF8')) END AS byte_length", $sql );
	}

	/**
	 * Tests formatted FROM_UNIXTIME() shares DATE_FORMAT coverage and NULL semantics.
	 */
	public function test_from_unixtime_formatted_runtime_function_is_translated_with_null_guard(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT FROM_UNIXTIME(0.123456, '%Y-%m-%d %H:%i:%s.%f') AS formatted_epoch, FROM_UNIXTIME(NULL, 'literal') AS null_literal, DATE_FORMAT(NULL, '%%') AS null_percent"
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( "TO_TIMESTAMP(CAST(0.123456 AS double precision)) AT TIME ZONE 'UTC'", $sql );
		$this->assertStringContainsString( "'YYYY'", $sql );
		$this->assertStringContainsString( "'MM'", $sql );
		$this->assertStringContainsString( "'DD'", $sql );
		$this->assertStringContainsString( "'HH24'", $sql );
		$this->assertStringContainsString( "'MI'", $sql );
		$this->assertStringContainsString( "'SS'", $sql );
		$this->assertStringContainsString( "'US'", $sql );
		$this->assertStringContainsString( "CASE WHEN CAST(TO_TIMESTAMP(CAST(NULL AS double precision)) AT TIME ZONE 'UTC' AS text) IS NULL OR", $sql );
		$this->assertStringContainsString( 'CASE WHEN CAST(NULL AS text) IS NULL OR', $sql );
		$this->assertStringNotContainsString( 'FROM_UNIXTIME', $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );
	}

	/**
	 * Tests DATE_FORMAT() supports runtime format expressions like SQLite's UDF.
	 */
	public function test_mysql_date_format_runtime_format_expressions_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT
				DATE_FORMAT(post_date, format_mask) AS dynamic_column_format,
				DATE_FORMAT(post_date, CONCAT('%Y', '-%m')) AS dynamic_expression_format,
				DATE_FORMAT(NULL, format_mask) AS null_date_format,
				DATE_FORMAT(post_date, NULL) AS null_mask_format
			FROM wptests_dynamic_date_formats"
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'WITH RECURSIVE "__wp_pg_mysql_date_format"', $sql );
		$this->assertStringContainsString( 'CAST(format_mask AS text)', $sql );
		$this->assertStringContainsString( "(CAST('%Y' AS text) || CAST('-%m' AS text))", $sql );
		$this->assertStringContainsString( "WHEN 'Y' THEN TO_CHAR", $sql );
		$this->assertStringContainsString( "WHEN 'D' THEN CAST(CAST(EXTRACT(DAY FROM", $sql );
		$this->assertStringContainsString( "WHEN 'w' THEN CAST(CAST(EXTRACT(DOW FROM", $sql );
		$this->assertStringContainsString( "ELSE '%' || SUBSTRING", $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			'SELECT DATE_FORMAT(post_date, format_mask) AS dynamic_column_format FROM wptests_dynamic_date_formats'
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'WITH RECURSIVE "__wp_pg_mysql_date_format"', $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );
	}

	/**
	 * Tests formatted FROM_UNIXTIME() supports runtime format expressions.
	 */
	public function test_from_unixtime_runtime_format_expression_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT FROM_UNIXTIME(0, format_mask) AS formatted_epoch
			FROM wptests_unix_time_formats"
		);

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'WITH RECURSIVE "__wp_pg_mysql_date_format"', $sql );
		$this->assertStringContainsString( "TO_TIMESTAMP(CAST(0 AS double precision)) AT TIME ZONE 'UTC'", $sql );
		$this->assertStringContainsString( 'CAST(format_mask AS text)', $sql );
		$this->assertStringNotContainsString( 'FROM_UNIXTIME', $sql );
	}

	/**
	 * Tests unsupported common MySQL runtime function forms are left without compatibility translations.
	 */
	public function test_unsupported_common_mysql_runtime_function_forms_return_null_translation(): void {
		$driver  = $this->create_driver();
		$queries = array(
			'SELECT CONCAT() AS empty_concat',
			'SELECT IFNULL(primary_value) AS invalid_ifnull FROM runtime_names',
			'SELECT LOG() AS invalid_log',
		);

		foreach ( $queries as $query ) {
			$this->assertNull(
				$this->translate_driver_query_with_private_method(
					$driver,
					'translate_mysql_compatible_query',
					$query
				),
				$query
			);
		}
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
	 * Tests MySQL SELECT row-locking clauses are stripped like the SQLite backend.
	 */
	public function test_select_row_locking_clauses_are_supported_noops(): void {
		$queries = array(
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' FOR UPDATE",
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' FOR SHARE",
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' LOCK IN SHARE MODE",
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' FOR UPDATE SKIP LOCKED",
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' FOR UPDATE NOWAIT",
			"SELECT value FROM wptests_locking WHERE name = 'test_lock' FOR SHARE OF wptests_locking NOWAIT",
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$driver->query( 'CREATE TABLE wptests_locking (name VARCHAR(255), value VARCHAR(255))' );
			$driver->query( "INSERT INTO wptests_locking (name, value) VALUES ('test_lock', '123')" );

			$rows = $driver->query( $query );

			$this->assertSame( '123', $rows[0]->value, $query );
			$this->assertSame(
				array(
					array(
						'sql'    => 'SELECT value FROM wptests_locking WHERE name = \'test_lock\'',
						'params' => array(),
					),
				),
				$driver->get_last_postgresql_queries(),
				$query
			);
		}
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

		$select = 'SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, QUARTER(post_date) AS q, DAYOFMONTH(post_date) AS d, DAY(post_date) AS day_value, HOUR(post_date) AS h, MINUTE(post_date) AS i, SECOND(post_date) AS s, EXTRACT(DAY FROM post_date) AS extracted_day, EXTRACT(QUARTER FROM post_date) AS extracted_quarter FROM wptests_posts WHERE ID = 1';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_zero_date_safe_extract_sql( 'YEAR', 'post_date' ) . ' AS y, ' . $this->get_expected_zero_date_safe_extract_sql( 'MONTH', 'post_date' ) . ' AS m, ' . $this->get_expected_zero_date_safe_extract_sql( 'QUARTER', 'post_date' ) . ' AS q, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS d, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS day_value, ' . $this->get_expected_zero_date_safe_extract_sql( 'HOUR', 'post_date' ) . ' AS h, ' . $this->get_expected_zero_date_safe_extract_sql( 'MINUTE', 'post_date' ) . ' AS i, ' . $this->get_expected_zero_date_safe_extract_sql( 'SECOND', 'post_date' ) . ' AS s, ' . $this->get_expected_zero_date_safe_extract_sql( 'DAY', 'post_date' ) . ' AS extracted_day, ' . $this->get_expected_zero_date_safe_extract_sql( 'QUARTER', 'post_date' ) . ' AS extracted_quarter FROM wptests_posts WHERE "ID" = 1',
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
				'name' => 'QUARTER',
				'unit' => 'QUARTER',
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
	 * Tests numeric MySQL DATE_FORMAT masks used by WordPress date queries are translated.
	 */
	public function test_mysql_date_format_numeric_masks_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_FORMAT(post_date, '%H.%i%s') AS hour_minute_second, DATE_FORMAT(post_date, '0.%i%s') AS minute_second_fraction FROM wptests_posts";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertNotNull( $sql );
		$this->assertStringContainsString( 'TO_CHAR(' . $this->get_expected_zero_date_safe_timestamp_sql( 'post_date' ) . ", 'HH24.MISS')", $sql );
		$this->assertStringContainsString( "'0.' || TO_CHAR(" . $this->get_expected_zero_date_safe_timestamp_sql( 'post_date' ) . ", 'MISS')", $sql );
		$this->assertStringContainsString( 'AS hour_minute_second', $sql );
		$this->assertStringContainsString( 'AS minute_second_fraction', $sql );
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
	 * Tests broader MySQL DATE_FORMAT specifiers are translated for PostgreSQL.
	 */
	public function test_mysql_date_format_extended_specifiers_are_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$sql = $this->translate_driver_query_with_private_method(
			$driver,
			'translate_mysql_compatible_query',
			"SELECT DATE_FORMAT(post_date, '%a %b %c %D %d %e %f %H %h %I %i %j %k %l %M %m %p %r %S %s %T %U %u %V %v %W %w %X %x %Y %y %%') AS formatted_date"
		);

		$this->assertNotNull( $sql );
		$timestamp_sql = $this->get_expected_zero_date_safe_timestamp_sql( 'post_date' );
		$formats       = array(
			'Dy',
			'Mon',
			'FMMM',
			'DD',
			'FMDD',
			'US',
			'HH24',
			'HH12',
			'MI',
			'DDD',
			'FMHH24',
			'FMHH12',
			'FMMonth',
			'MM',
			'AM',
			'HH12:MI:SS AM',
			'SS',
			'HH24:MI:SS',
			'WW',
			'IW',
			'FMDay',
			'YYYY',
			'IYYY',
			'YY',
		);
		foreach ( $formats as $format ) {
			$this->assertStringContainsString( 'TO_CHAR(' . $timestamp_sql . ", '" . $format . "')", $sql, $format );
		}

		$this->assertStringContainsString( 'CAST(EXTRACT(DAY FROM ' . $timestamp_sql . ') AS integer)', $sql );
		$this->assertStringContainsString( 'CAST(CAST(EXTRACT(DOW FROM ' . $timestamp_sql . ') AS integer) AS text)', $sql );
		$this->assertStringContainsString( "'%'", $sql );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $sql );
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
	 * Tests DATE_ADD supports simple MySQL interval units for PostgreSQL.
	 */
	public function test_mysql_date_add_supports_simple_mysql_interval_units_for_postgresql(): void {
		$driver = $this->create_driver();
		$units  = array(
			'MICROSECOND'         => 'microsecond',
			'SECOND'              => 'second',
			'MINUTE'              => 'minute',
			'HOUR'                => 'hour',
			'DAY'                 => 'day',
			'WEEK'                => 'week',
			'MONTH'               => 'month',
			'QUARTER'             => '3 months',
			'YEAR'                => 'year',
			'SQL_TSI_MICROSECOND' => 'microsecond',
			'SQL_TSI_SECOND'      => 'second',
			'SQL_TSI_MINUTE'      => 'minute',
			'SQL_TSI_HOUR'        => 'hour',
			'SQL_TSI_DAY'         => 'day',
			'SQL_TSI_WEEK'        => 'week',
			'SQL_TSI_MONTH'       => 'month',
			'SQL_TSI_QUARTER'     => '3 months',
			'SQL_TSI_YEAR'        => 'year',
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
	 * Tests TIMESTAMPADD supports simple MySQL interval units for PostgreSQL.
	 */
	public function test_mysql_timestampadd_supports_simple_mysql_interval_units_for_postgresql(): void {
		$driver = $this->create_driver();
		$units  = array(
			'MICROSECOND'     => 'microsecond',
			'SECOND'          => 'second',
			'MINUTE'          => 'minute',
			'HOUR'            => 'hour',
			'DAY'             => 'day',
			'WEEK'            => 'week',
			'MONTH'           => 'month',
			'QUARTER'         => '3 months',
			'YEAR'            => 'year',
			'SQL_TSI_MINUTE'  => 'minute',
			'SQL_TSI_QUARTER' => '3 months',
		);

		foreach ( $units as $mysql_unit => $postgresql_unit ) {
			$select = 'SELECT TIMESTAMPADD(' . $mysql_unit . ', 2, post_date_gmt) AS shifted';
			$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

			$this->assertSame(
				'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'post_date_gmt', '2', $postgresql_unit ) . ' AS shifted',
				$sql,
				$mysql_unit
			);
			$this->assertStringNotContainsString( 'TIMESTAMPADD', $sql, $mysql_unit );
		}
	}

	/**
	 * Tests fractional SECOND interval values preserve MySQL numeric coercion.
	 */
	public function test_mysql_date_arithmetic_preserves_fractional_second_intervals_for_postgresql(): void {
		$driver = $this->create_driver();
		$cases  = array(
			array(
				'SELECT DATE_ADD(post_date_gmt, INTERVAL 0.5 SECOND) AS shifted',
				'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'post_date_gmt', '0.5', 'second' ) . ' AS shifted',
				'DATE_ADD',
			),
			array(
				"SELECT DATE_SUB(post_date_gmt, INTERVAL '0.25' SECOND) AS shifted",
				'SELECT ' . $this->get_expected_date_arithmetic_sql( '-', 'post_date_gmt', "'0.25'", 'second' ) . ' AS shifted',
				'DATE_SUB',
			),
			array(
				'SELECT TIMESTAMPADD(SECOND, 0.5, post_date_gmt) AS shifted',
				'SELECT ' . $this->get_expected_date_arithmetic_sql( '+', 'post_date_gmt', '0.5', 'second' ) . ' AS shifted',
				'TIMESTAMPADD',
			),
		);

		foreach ( $cases as $case ) {
			list( $select, $expected, $function_name ) = $case;

			$sql = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

			$this->assertSame( $expected, $sql, $select );
			$this->assertStringContainsString( ' AS numeric)', $sql, $select );
			$this->assertStringNotContainsString( $function_name, $sql, $select );
		}
	}

	/**
	 * Tests DATE_ADD translates full MySQL composite interval literals exactly.
	 */
	public function test_mysql_date_add_supports_composite_interval_literals_for_postgresql(): void {
		$driver = $this->create_driver();
		$cases  = array(
			array( 'SECOND_MICROSECOND', "'10.09'", array( array( '10', 'second' ), array( '090000', 'microsecond' ) ) ),
			array( 'MINUTE_SECOND', "'1:02'", array( array( '1', 'minute' ), array( '02', 'second' ) ) ),
			array( 'MINUTE_MICROSECOND', "'3:04.005006'", array( array( '3', 'minute' ), array( '04', 'second' ), array( '005006', 'microsecond' ) ) ),
			array( 'HOUR_MINUTE', "'5:30'", array( array( '5', 'hour' ), array( '30', 'minute' ) ) ),
			array( 'HOUR_SECOND', "'6:07:08'", array( array( '6', 'hour' ), array( '07', 'minute' ), array( '08', 'second' ) ) ),
			array( 'HOUR_MICROSECOND', "'9:10:11.000012'", array( array( '9', 'hour' ), array( '10', 'minute' ), array( '11', 'second' ), array( '000012', 'microsecond' ) ) ),
			array( 'DAY_HOUR', "'13 14'", array( array( '13', 'day' ), array( '14', 'hour' ) ) ),
			array( 'DAY_MINUTE', "'15 16:17'", array( array( '15', 'day' ), array( '16', 'hour' ), array( '17', 'minute' ) ) ),
			array( 'DAY_SECOND', "'18 19:20:21'", array( array( '18', 'day' ), array( '19', 'hour' ), array( '20', 'minute' ), array( '21', 'second' ) ) ),
			array( 'DAY_MICROSECOND', "'22 23:24:25.123456'", array( array( '22', 'day' ), array( '23', 'hour' ), array( '24', 'minute' ), array( '25', 'second' ), array( '123456', 'microsecond' ) ) ),
			array( 'YEAR_MONTH', "'2-03'", array( array( '2', 'year' ), array( '03', 'month' ) ) ),
		);

		foreach ( $cases as $case ) {
			list( $mysql_unit, $value_sql, $components ) = $case;

			$select = 'SELECT DATE_ADD(post_date_gmt, INTERVAL ' . $value_sql . ' ' . $mysql_unit . ') AS shifted';
			$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

			$this->assertSame(
				'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql( '+', 'post_date_gmt', $this->get_expected_mysql_composite_interval_sql( $components ) ) . ' AS shifted',
				$sql,
				$mysql_unit
			);
			$this->assertStringNotContainsString( 'DATE_ADD', $sql, $mysql_unit );
		}
	}

	/**
	 * Tests DATE_SUB translates MySQL composite interval literals exactly.
	 */
	public function test_mysql_date_sub_supports_composite_interval_literals_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_SUB(post_date_gmt, INTERVAL '1 02:03:04.005006' DAY_MICROSECOND) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'-',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '1', 'day' ),
						array( '02', 'hour' ),
						array( '03', 'minute' ),
						array( '04', 'second' ),
						array( '005006', 'microsecond' ),
					)
				)
			) . ' AS shifted',
			$sql
		);
		$this->assertStringNotContainsString( 'DATE_SUB', $sql );
	}

	/**
	 * Tests negative composite interval signs apply to every component.
	 */
	public function test_mysql_date_add_negative_composite_interval_sign_applies_to_all_parts_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_ADD(post_date_gmt, INTERVAL '-5:30' HOUR_MINUTE) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'+',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '-5', 'hour' ),
						array( '-30', 'minute' ),
					)
				)
			) . ' AS shifted',
			$sql
		);

		$select = 'SELECT DATE_ADD(post_date_gmt, INTERVAL 1.5 HOUR_MINUTE) AS shifted';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'+',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '1', 'hour' ),
						array( '5', 'minute' ),
					)
				)
			) . ' AS shifted',
			$sql
		);
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
	 * Tests DATE_ADD supports WordPress upgrade HOUR_MINUTE interval values.
	 */
	public function test_mysql_date_add_supports_hour_minute_interval_values_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_ADD(post_date_gmt, INTERVAL '5:30' HOUR_MINUTE) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'+',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '5', 'hour' ),
						array( '30', 'minute' ),
					)
				)
			) . ' AS shifted',
			$sql
		);
		$this->assertStringContainsString( "INTERVAL '1 hour'", $sql );
		$this->assertStringContainsString( "INTERVAL '1 minute'", $sql );
		$this->assertStringNotContainsString( 'DATE_ADD', $sql );
	}

	/**
	 * Tests composite interval literals support MySQL's right-aligned short values.
	 */
	public function test_mysql_date_add_supports_short_composite_interval_literals_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = 'SELECT DATE_ADD(post_date_gmt, INTERVAL 2 HOUR_MINUTE) AS shifted';
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'+',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '2', 'minute' ),
					)
				)
			) . ' AS shifted',
			$sql
		);

		$select = "SELECT DATE_SUB(post_date_gmt, INTERVAL '1:2' DAY_SECOND) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'-',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '1', 'minute' ),
						array( '2', 'second' ),
					)
				)
			) . ' AS shifted',
			$sql
		);
	}

	/**
	 * Tests composite interval literals accept alternate MySQL delimiters.
	 */
	public function test_mysql_date_add_supports_alternate_composite_interval_delimiters_for_postgresql(): void {
		$driver = $this->create_driver();

		$select = "SELECT DATE_ADD(post_date_gmt, INTERVAL '6/4' HOUR_MINUTE) AS shifted";
		$sql    = $this->translate_driver_query_with_private_method( $driver, 'translate_mysql_compatible_query', $select );

		$this->assertSame(
			'SELECT ' . $this->get_expected_date_arithmetic_with_interval_sql(
				'+',
				'post_date_gmt',
				$this->get_expected_mysql_composite_interval_sql(
					array(
						array( '6', 'hour' ),
						array( '4', 'minute' ),
					)
				)
			) . ' AS shifted',
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
	 * Tests invalid DATE_ADD interval units and unsafe composite shapes fail closed.
	 */
	public function test_mysql_date_add_with_unsupported_interval_shape_fails_closed(): void {
		$driver  = $this->create_driver();
		$queries = array(
			'SELECT DATE_ADD(post_date_gmt, INTERVAL 1 fortnight) AS shifted',
			'SELECT DATE_ADD(post_date_gmt, INTERVAL 6/4 HOUR_MINUTE) AS shifted',
			"SELECT DATE_ADD(post_date_gmt, INTERVAL '1:2:3' MINUTE_SECOND) AS shifted",
		);

		foreach ( $queries as $query ) {
			$this->assertNull(
				$this->translate_driver_query_with_private_method(
					$driver,
					'translate_mysql_compatible_query',
					$query
				),
				$query
			);
		}
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE supports constant scalar subquery assignments.
	 */
	public function test_options_upsert_scalar_subquery_assignment_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('siteurl', 'old', 'yes')" );

		$upsert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('siteurl', 'http://example.org', 'yes')
			ON DUPLICATE KEY UPDATE `option_value` = (SELECT 'http://example.net')";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'siteurl\', \'http://example.org\', \'yes\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = CAST((SELECT \'http://example.net\') AS text)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'http://example.net', $rows[0]->option_value );
	}

	/**
	 * Tests ON DUPLICATE KEY UPDATE supports table-backed COUNT() scalar subquery assignments.
	 */
	public function test_options_upsert_table_backed_count_subquery_assignment_is_translated_to_postgresql(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );
		$driver->query(
			'CREATE TABLE wptests_upsert_source (
				id INTEGER PRIMARY KEY,
				label TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_upsert_source (
				id int(11) NOT NULL,
				label varchar(20) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('source_counts', '0', '0')" );
		$driver->query( "INSERT INTO wptests_upsert_source (id, label) VALUES (1, 'one'), (2, 'two'), (3, 'three')" );

		$upsert = "INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
			VALUES ('source_counts', 'ignored', 'ignored')
			ON DUPLICATE KEY UPDATE `option_value` = (SELECT COUNT(*) FROM `wptests_upsert_source`),
			                        `autoload` = (SELECT COUNT(s.id) FROM `wptests_upsert_source` AS s)";

		$this->assertSame( 1, $driver->query( $upsert ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'INSERT INTO "wptests_options" ("option_name", "option_value", "autoload") VALUES (\'source_counts\', \'ignored\', \'ignored\') ON CONFLICT ("option_name") DO UPDATE SET "option_value" = CAST((SELECT COUNT(*) FROM "wptests_upsert_source") AS text), "autoload" = CAST((SELECT COUNT("s"."id") FROM "wptests_upsert_source" AS "s") AS text)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$rows = $driver->query( "SELECT option_value, autoload FROM wptests_options WHERE option_name = 'source_counts'" );

		$this->assertCount( 1, $rows );
		$this->assertSame( '3', $rows[0]->option_value );
		$this->assertSame( '3', $rows[0]->autoload );
	}

	/**
	 * Tests unsupported table-backed scalar subquery assignments fail closed.
	 */
	public function test_options_upsert_unsupported_table_backed_subquery_assignment_fails_closed(): void {
		$driver = $this->create_driver();

		$this->install_options_table_with_mysql_metadata( $driver );
		$driver->query(
			'CREATE TABLE wptests_upsert_source (
				id INTEGER PRIMARY KEY,
				label TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_upsert_source (
				id int(11) NOT NULL,
				label varchar(20) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('source_counts', '0', '0')" );

		$queries = array(
			"INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
				VALUES ('source_counts', 'ignored', 'ignored')
				ON DUPLICATE KEY UPDATE `option_value` = (SELECT COUNT(*) FROM `wptests_upsert_source` WHERE id > 0)",
			"INSERT INTO `wptests_options` (`option_name`, `option_value`, `autoload`)
				VALUES ('source_counts', 'ignored', 'ignored')
				ON DUPLICATE KEY UPDATE `option_value` = (SELECT label FROM `wptests_upsert_source`)",
		);

		foreach ( $queries as $query ) {
			$this->assertNull(
				$this->translate_driver_query_data_with_private_method(
					$driver,
					'translate_mysql_on_duplicate_key_update_query',
					$query
				),
				$query
			);

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported table-backed scalar subquery assignment to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported ON DUPLICATE KEY UPDATE statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
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
	 * Tests DESCRIBE/DESC accepts current database-qualified table references.
	 */
	public function test_describe_accepts_current_database_qualification_forms(): void {
		$cases = array(
			'DESCRIBE wptests.wptests_options',
			'DESC public.wptests_options',
			'DESC `wptests`.`wptests_options`',
		);

		foreach ( $cases as $query ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			$result = $driver->query( $query );

			$this->assertCount( 4, $result, $query );
			$this->assertSame( 'option_id', $result[0]->Field, $query );
			$this->assertSame( 'autoload', $result[3]->Field, $query );

			$queries = $driver->get_last_postgresql_queries();
			$this->assertCount( 1, $queries, $query );
			$this->assertStringContainsString( 'information_schema.columns', $queries[0]['sql'], $query );
			$this->assertStringNotContainsString( 'DESCRIBE', $queries[0]['sql'], $query );
			$this->assertStringNotContainsString( 'DESC', $queries[0]['sql'], $query );
			$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'], $query );
		}
	}

	/**
	 * Tests unsupported DESCRIBE/DESC qualifiers fail before backend execution.
	 */
	public function test_describe_unsupported_qualification_does_not_reach_backend(): void {
		$queries = array(
			'DESC other_db.wptests_options'           => 'Unsupported DESCRIBE statement.',
			'DESC information_schema.wptests_options' => 'Unsupported information_schema query.',
			'DESC wptests.wptests_options.extra'      => 'Unsupported DESCRIBE statement.',
		);

		foreach ( $queries as $query => $message ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported DESCRIBE statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
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
	 * Tests CHANGE/MODIFY COLUMN preserve MySQL JSON metadata parity.
	 */
	public function test_alter_table_change_and_modify_json_update_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_json_alter (
				payload longtext COLLATE koi8r_general_ci NOT NULL,
				settings JSON DEFAULT NULL
			)"
		);

		$driver->query( 'ALTER TABLE wptests_json_alter CHANGE COLUMN payload payload JSON DEFAULT NULL' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "payload" TYPE text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "payload" DROP NOT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "payload" SET DEFAULT NULL',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_json_alter' );
		$this->assertSame( 'json', $columns[0]['column_type'] );
		$this->assertNull( $columns[0]['character_set_name'] );
		$this->assertNull( $columns[0]['collation_name'] );
		$this->assertSame( 'YES', $columns[0]['is_nullable'] );
		$this->assertNull( $columns[0]['column_default'] );

		$full = $driver->query( 'SHOW FULL COLUMNS FROM wptests_json_alter' );
		$this->assertSame( 'payload', $full[0]->Field );
		$this->assertSame( 'json', $full[0]->Type );
		$this->assertNull( $full[0]->Collation );
		$this->assertNull( $full[0]->Default );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_json_alter' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  `payload` json DEFAULT NULL', $create_table );

		$driver->query( 'ALTER TABLE wptests_json_alter MODIFY COLUMN settings LONGTEXT COLLATE koi8r_general_ci NOT NULL' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "settings" TYPE text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "settings" SET NOT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_json_alter" ALTER COLUMN "settings" DROP DEFAULT',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_json_alter' );
		$this->assertSame( 'longtext', $columns[1]['column_type'] );
		$this->assertSame( 'koi8r', $columns[1]['character_set_name'] );
		$this->assertSame( 'koi8r_general_ci', $columns[1]['collation_name'] );
		$this->assertSame( 'NO', $columns[1]['is_nullable'] );
	}

	/**
	 * Tests mixed ALTER TABLE batches add columns and indexes while ignoring MySQL placement.
	 */
	public function test_alter_table_add_batch_updates_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_alter (
				id int(11) NOT NULL,
				status varchar(20) DEFAULT 'draft',
				PRIMARY KEY (id)
			)"
		);

		$driver->query(
			'ALTER TABLE wptests_plugin_alter
				ADD flag tinyint(1) NOT NULL DEFAULT 0,
				ADD COLUMN code varchar(20) DEFAULT "x" AFTER id,
				ADD KEY flag_idx (flag DESC)'
		);

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_plugin_alter" ADD COLUMN "flag" integer NOT NULL DEFAULT \'0\'',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_plugin_alter" ADD COLUMN "code" varchar(20) DEFAULT \'x\'',
					'params' => array(),
				),
				array(
					'sql'    => 'CREATE INDEX "wptests_plugin_alter__flag_idx" ON "wptests_plugin_alter" ("flag" DESC)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_alter' );
		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_plugin_alter' );

		$this->assertSame( array( 'id', 'status', 'flag', 'code' ), array_column( $columns, 'column_name' ) );
		$this->assertSame( array( 'PRIMARY', 'flag_idx' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
		$this->assertSame( 'D', $indexes[1]['collation'] );

		$show_index = $driver->query( "SHOW INDEX FROM wptests_plugin_alter WHERE Key_name = 'flag_idx'" );
		$this->assertSame( 'D', $show_index[0]->Collation );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_plugin_alter' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  KEY `flag_idx` (`flag` DESC)', $create_table );
	}

	/**
	 * Tests alias-only CREATE TABLE statements use the MySQL DDL translator.
	 */
	public function test_create_table_with_only_mysql_type_alias_markers_uses_translator(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				'CREATE TABLE wptests_alias_create (
					flags BIT(10),
					enabled BOOL,
					amount DEC(10,2),
					fixed_value FIXED(8,3),
					real_value REAL
				)'
			)
		);

		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TABLE \"wptests_alias_create\" (\n  \"flags\" integer,\n  \"enabled\" integer,\n  \"amount\" numeric(10,2),\n  \"fixed_value\" numeric(8,3),\n  \"real_value\" double precision\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_alias_create' );
		$this->assertSame(
			array( 'bit(10)', 'bool', 'dec(10,2)', 'fixed(8,3)', 'real' ),
			array_column( $columns, 'column_type' )
		);
	}

	/**
	 * Tests CREATE TABLE stores MySQL JSON metadata while using text storage.
	 */
	public function test_create_table_json_uses_text_storage_with_mysql_metadata(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				'CREATE TABLE wptests_json_create (
					id int NOT NULL,
					payload JSON DEFAULT NULL
				)'
			)
		);

		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TABLE \"wptests_json_create\" (\n  \"id\" integer NOT NULL,\n  \"payload\" text DEFAULT NULL\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_json_create' );
		$this->assertSame( 'json', $columns[1]['column_type'] );
		$this->assertNull( $columns[1]['character_set_name'] );
		$this->assertNull( $columns[1]['collation_name'] );

		$this->install_information_schema_fixture( $driver );
		$describe = $driver->query( 'DESC wptests_json_create' );
		$this->assertSame( 'payload', $describe[1]->Field );
		$this->assertSame( 'json', $describe[1]->Type );
		$this->assertNull( $describe[1]->Default );

		$full = $driver->query( 'SHOW FULL COLUMNS FROM wptests_json_create' );
		$this->assertSame( 'payload', $full[1]->Field );
		$this->assertSame( 'json', $full[1]->Type );
		$this->assertNull( $full[1]->Collation );
		$this->assertNull( $full[1]->Default );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_json_create' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  `payload` json DEFAULT NULL', $create_table );

		$information_schema = $driver->query(
			"SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT
			FROM information_schema.columns
			WHERE table_name = 'wptests_json_create'
				AND column_name = 'payload'"
		);

		$this->assertCount( 1, $information_schema );
		$this->assertSame( 'payload', $information_schema[0]->COLUMN_NAME );
		$this->assertSame( 'json', $information_schema[0]->DATA_TYPE );
		$this->assertSame( 'json', $information_schema[0]->COLUMN_TYPE );
		$this->assertNull( $information_schema[0]->CHARACTER_SET_NAME );
		$this->assertNull( $information_schema[0]->COLLATION_NAME );
		$this->assertNull( $information_schema[0]->COLUMN_DEFAULT );
	}

	/**
	 * Tests CREATE TABLE routes LONG-prefixed MySQL aliases through the DDL translator.
	 */
	public function test_create_table_long_aliases_use_sqlite_compatible_metadata(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			0,
			$driver->query(
				'CREATE TABLE wptests_long_alias_create (
					notes LONG VARCHAR,
					raw_data LONG VARBINARY
				)'
			)
		);

		$this->assertSame(
			array(
				array(
					'sql'    => "CREATE TABLE \"wptests_long_alias_create\" (\n  \"notes\" text,\n  \"raw_data\" bytea\n)",
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_long_alias_create' );
		$this->assertSame( array( 'mediumtext', 'mediumblob' ), array_column( $columns, 'column_type' ) );
		$this->assertSame( 'utf8mb4', $columns[0]['character_set_name'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $columns[0]['collation_name'] );
		$this->assertNull( $columns[1]['character_set_name'] );
		$this->assertNull( $columns[1]['collation_name'] );
	}

	/**
	 * Tests ALTER TABLE ADD accepts MySQL data type aliases.
	 */
	public function test_alter_table_add_accepts_mysql_data_type_aliases(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_alias_alter (
				id int(11) NOT NULL,
				PRIMARY KEY (id)
			)"
		);

		$driver->query(
			'ALTER TABLE wptests_alias_alter
				ADD flags BIT(10),
				ADD enabled BOOL NOT NULL DEFAULT 0,
				ADD toggled BOOLEAN,
				ADD amount DEC(10,2),
				ADD fixed_value FIXED(8,3),
				ADD real_value REAL,
				ADD payload JSON DEFAULT NULL,
				ADD notes LONG VARCHAR,
				ADD raw_data LONG VARBINARY'
		);

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "flags" integer',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "enabled" integer NOT NULL DEFAULT \'0\'',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "toggled" integer',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "amount" numeric(10,2)',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "fixed_value" numeric(8,3)',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "real_value" double precision',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "payload" text DEFAULT NULL',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "notes" text',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alias_alter" ADD COLUMN "raw_data" bytea',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_alias_alter' );
		$this->assertSame(
			array( 'int(11)', 'bit(10)', 'bool', 'boolean', 'dec(10,2)', 'fixed(8,3)', 'real', 'json', 'mediumtext', 'mediumblob' ),
			array_column( $columns, 'column_type' )
		);
		$this->assertNull( $columns[7]['character_set_name'] );
		$this->assertNull( $columns[7]['collation_name'] );
		$this->assertSame( 'utf8mb4', $columns[8]['character_set_name'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $columns[8]['collation_name'] );
		$this->assertNull( $columns[9]['character_set_name'] );
		$this->assertNull( $columns[9]['collation_name'] );
	}

	/**
	 * Tests ALTER TABLE accepts current database-qualified targets and updates metadata.
	 */
	public function test_alter_table_accepts_current_database_qualified_targets_and_updates_metadata(): void {
		$driver = $this->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wp.t (id INT PRIMARY KEY)' );

		$driver->query( 'ALTER TABLE wp.t ADD COLUMN name VARCHAR(255)' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "t" ADD COLUMN "name" varchar(255)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$driver->query( "ALTER TABLE `wp`.`t` ADD COLUMN `slug` VARCHAR(191) DEFAULT 'x'" );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "t" ADD COLUMN "slug" varchar(191) DEFAULT \'x\'',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 't' );

		$this->assertSame( array( 'id', 'name', 'slug' ), array_column( $columns, 'column_name' ) );
		$this->assertSame( 'varchar(255)', $columns[1]['column_type'] );
		$this->assertSame( 'varchar(191)', $columns[2]['column_type'] );
		$this->assertSame( 'x', $columns[2]['column_default'] );
	}

	/**
	 * Tests ALTER TABLE rejects non-current schema-qualified targets before backend execution.
	 */
	public function test_alter_table_rejects_non_current_schema_qualified_targets(): void {
		$queries = array(
			'ALTER TABLE other_db.t ADD COLUMN name VARCHAR(255)'                 => 'Unsupported ALTER TABLE statement.',
			'ALTER TABLE information_schema.tables ADD COLUMN name VARCHAR(255)' => 'Unsupported information_schema query.',
		);

		foreach ( $queries as $query => $message ) {
			$driver = $this->create_driver( 'wp' );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported ALTER TABLE target to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}

		$driver = $this->create_driver( 'wp' );
		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		try {
			$driver->query( 'ALTER TABLE tables ADD COLUMN name VARCHAR(255)' );
			$this->fail( 'Expected information_schema ALTER TABLE target to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests CREATE TABLE inline constraints update PostgreSQL and MySQL-facing metadata.
	 */
	public function test_create_table_inline_constraints_update_postgresql_and_show_create_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_inline_parent (id int(11) PRIMARY KEY) DEFAULT CHARACTER SET utf8mb4' );
		$driver->query(
			'CREATE TABLE wptests_inline_child (
				id int(11) PRIMARY KEY,
				slug varchar(100) UNIQUE,
				score int CHECK (score > 0),
				parent_id int(11) REFERENCES wptests_inline_parent(id) ON DELETE CASCADE ON UPDATE SET NULL
			) DEFAULT CHARACTER SET utf8mb4'
		);
		$this->assertStringContainsString(
			'CONSTRAINT "wptests_inline_child_chk_1" CHECK (score > 0)',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$this->assertSame(
			array(
				array(
					'key_name'     => 'PRIMARY',
					'seq_in_index' => '1',
					'column_name'  => 'id',
					'non_unique'   => '0',
					'index_type'   => 'BTREE',
					'collation'    => 'A',
					'sub_part'     => null,
					'nullable'     => '',
				),
				array(
					'key_name'     => 'slug',
					'seq_in_index' => '1',
					'column_name'  => 'slug',
					'non_unique'   => '0',
					'index_type'   => 'BTREE',
					'collation'    => 'A',
					'sub_part'     => null,
					'nullable'     => 'YES',
				),
			),
			$this->get_mysql_index_metadata_rows( $driver, 'wptests_inline_child' )
		);

		$this->assertSame(
			array(
				array(
					'constraint_name'        => 'wptests_inline_child_ibfk_1',
					'seq_in_index'           => '1',
					'column_name'            => 'parent_id',
					'referenced_table_name'  => 'wptests_inline_parent',
					'referenced_column_name' => 'id',
					'update_rule'            => 'SET NULL',
					'delete_rule'            => 'CASCADE',
				),
			),
			$this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_inline_child' )
		);

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_inline_child' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  PRIMARY KEY (`id`)', $create_table );
		$this->assertStringContainsString( '  UNIQUE KEY `slug` (`slug`)', $create_table );
		$this->assertStringContainsString(
			'  CONSTRAINT `wptests_inline_child_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `wptests_inline_parent` (`id`) ON DELETE CASCADE ON UPDATE SET NULL',
			$create_table
		);
	}

	/**
	 * Tests ALTER TABLE ADD COLUMN inline constraints update PostgreSQL and MySQL-facing metadata.
	 */
	public function test_alter_table_add_column_inline_constraints_update_postgresql_and_show_create_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_inline_child (
				existing_parent_id int(11)
			) DEFAULT CHARACTER SET utf8mb4'
		);

		$driver->query(
			'ALTER TABLE wptests_alter_inline_child
				ADD FOREIGN KEY (existing_parent_id) REFERENCES wptests_inline_parent(id),
				ADD COLUMN id int(11) PRIMARY KEY,
				ADD COLUMN slug varchar(100) UNIQUE,
				ADD COLUMN parent_id int(11) REFERENCES wptests_inline_parent(id) ON DELETE CASCADE ON UPDATE SET NULL'
		);

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_inline_child" ADD CONSTRAINT "wptests_alter_inline_child_ibfk_1" FOREIGN KEY ("existing_parent_id") REFERENCES "wptests_inline_parent" ("id")',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_inline_child" ADD COLUMN "id" integer PRIMARY KEY',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_inline_child" ADD COLUMN "slug" varchar(100) UNIQUE',
					'params' => array(),
				),
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_inline_child" ADD COLUMN "parent_id" integer CONSTRAINT "wptests_alter_inline_child_ibfk_2" REFERENCES "wptests_inline_parent" ("id") ON DELETE CASCADE ON UPDATE SET NULL',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame(
			array(
				array(
					'key_name'     => 'PRIMARY',
					'seq_in_index' => '1',
					'column_name'  => 'id',
					'non_unique'   => '0',
					'index_type'   => 'BTREE',
					'collation'    => 'A',
					'sub_part'     => null,
					'nullable'     => '',
				),
				array(
					'key_name'     => 'slug',
					'seq_in_index' => '1',
					'column_name'  => 'slug',
					'non_unique'   => '0',
					'index_type'   => 'BTREE',
					'collation'    => 'A',
					'sub_part'     => null,
					'nullable'     => 'YES',
				),
			),
			$this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_inline_child' )
		);

		$this->assertSame(
			array(
				array(
					'constraint_name'        => 'wptests_alter_inline_child_ibfk_1',
					'seq_in_index'           => '1',
					'column_name'            => 'existing_parent_id',
					'referenced_table_name'  => 'wptests_inline_parent',
					'referenced_column_name' => 'id',
					'update_rule'            => 'NO ACTION',
					'delete_rule'            => 'NO ACTION',
				),
				array(
					'constraint_name'        => 'wptests_alter_inline_child_ibfk_2',
					'seq_in_index'           => '1',
					'column_name'            => 'parent_id',
					'referenced_table_name'  => 'wptests_inline_parent',
					'referenced_column_name' => 'id',
					'update_rule'            => 'SET NULL',
					'delete_rule'            => 'CASCADE',
				),
			),
			$this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_alter_inline_child' )
		);

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_alter_inline_child' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  PRIMARY KEY (`id`)', $create_table );
		$this->assertStringContainsString( '  UNIQUE KEY `slug` (`slug`)', $create_table );
		$this->assertStringContainsString(
			'  CONSTRAINT `wptests_alter_inline_child_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `wptests_inline_parent` (`id`) ON DELETE CASCADE ON UPDATE SET NULL',
			$create_table
		);
	}

	/**
	 * Tests ALTER TABLE ADD COLUMN inline CHECK constraints are translated.
	 */
	public function test_alter_table_add_column_supports_inline_check_constraint(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_alter_inline_check (id int(11))' );

		$driver->query( 'ALTER TABLE wptests_alter_inline_check ADD COLUMN score int CHECK (score > 0)' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_alter_inline_check" ADD COLUMN "score" integer CONSTRAINT "wptests_alter_inline_check_chk_1" CHECK (score > 0)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_alter_inline_check' );
		$this->assertSame( array( 'id', 'score' ), array_column( $columns, 'column_name' ) );
		$this->assertSame( 'int(11)', $columns[0]['column_type'] );
		$this->assertSame( 'int', $columns[1]['column_type'] );
	}

	/**
	 * Tests ALTER TABLE ADD COLUMN NOT ENFORCED CHECK constraints fail explicitly.
	 */
	public function test_alter_table_add_column_rejects_not_enforced_inline_check_constraint(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_alter_inline_check (id int(11))' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported NOT ENFORCED CHECK constraint.' );

		$driver->query( 'ALTER TABLE wptests_alter_inline_check ADD COLUMN score int CHECK (score > 0) NOT ENFORCED' );
	}

	/**
	 * Tests ALTER TABLE ADD FOREIGN KEY forms update PostgreSQL and SHOW CREATE metadata.
	 */
	public function test_alter_table_add_foreign_key_updates_postgresql_and_show_create_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_fk_parent (id int NOT NULL, code int NOT NULL, PRIMARY KEY (id, code))' );
		$driver->query( 'CREATE TABLE wptests_fk_child (id int, code int)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_fk_child (id int, code int)' );

		$driver->query( 'ALTER TABLE wptests_fk_child ADD FOREIGN KEY (id) REFERENCES wptests_fk_parent (id)' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_fk_child" ADD CONSTRAINT "wptests_fk_child_ibfk_1" FOREIGN KEY ("id") REFERENCES "wptests_fk_parent" ("id")',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$driver->query(
			'ALTER TABLE wptests_fk_child
				ADD CONSTRAINT fk_child_parent FOREIGN KEY (id, code)
				REFERENCES wptests_fk_parent (id, code)
				ON DELETE CASCADE ON UPDATE SET NULL'
		);
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_fk_child" ADD CONSTRAINT "fk_child_parent" FOREIGN KEY ("id", "code") REFERENCES "wptests_fk_parent" ("id", "code") ON DELETE CASCADE ON UPDATE SET NULL',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame(
			array(
				array(
					'constraint_name'        => 'fk_child_parent',
					'seq_in_index'           => '1',
					'column_name'            => 'id',
					'referenced_table_name'  => 'wptests_fk_parent',
					'referenced_column_name' => 'id',
					'update_rule'            => 'SET NULL',
					'delete_rule'            => 'CASCADE',
				),
				array(
					'constraint_name'        => 'fk_child_parent',
					'seq_in_index'           => '2',
					'column_name'            => 'code',
					'referenced_table_name'  => 'wptests_fk_parent',
					'referenced_column_name' => 'code',
					'update_rule'            => 'SET NULL',
					'delete_rule'            => 'CASCADE',
				),
				array(
					'constraint_name'        => 'wptests_fk_child_ibfk_1',
					'seq_in_index'           => '1',
					'column_name'            => 'id',
					'referenced_table_name'  => 'wptests_fk_parent',
					'referenced_column_name' => 'id',
					'update_rule'            => 'NO ACTION',
					'delete_rule'            => 'NO ACTION',
				),
			),
			$this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_fk_child' )
		);

		$show_create = $driver->query( 'SHOW CREATE TABLE wptests_fk_child' );
		$this->assertStringContainsString(
			'  CONSTRAINT `fk_child_parent` FOREIGN KEY (`id`, `code`) REFERENCES `wptests_fk_parent` (`id`, `code`) ON DELETE CASCADE ON UPDATE SET NULL',
			$show_create[0]->{'Create Table'}
		);
		$this->assertStringContainsString(
			'  CONSTRAINT `wptests_fk_child_ibfk_1` FOREIGN KEY (`id`) REFERENCES `wptests_fk_parent` (`id`)',
			$show_create[0]->{'Create Table'}
		);
		$this->assertStringContainsString(
			WP_PostgreSQL_Driver::MYSQL_FOREIGN_KEY_METADATA_TABLE,
			$driver->get_last_postgresql_queries()[2]['sql']
		);
	}

	/**
	 * Tests ALTER TABLE DROP FOREIGN KEY updates PostgreSQL and SHOW CREATE metadata.
	 */
	public function test_alter_table_drop_foreign_key_updates_postgresql_and_show_create_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->query( 'CREATE TABLE wptests_fk_drop_parent (id int NOT NULL, PRIMARY KEY (id))' );
		$driver->query( 'CREATE TABLE wptests_fk_drop_child (id int)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_fk_drop_child (id int)' );
		$driver->query( 'ALTER TABLE wptests_fk_drop_child ADD FOREIGN KEY (id) REFERENCES wptests_fk_drop_parent (id)' );
		$driver->query( 'ALTER TABLE wptests_fk_drop_child ADD CONSTRAINT fk_drop_parent FOREIGN KEY (id) REFERENCES wptests_fk_drop_parent (id) ON DELETE CASCADE' );

		$driver->query( 'ALTER TABLE wptests_fk_drop_child DROP FOREIGN KEY wptests_fk_drop_child_ibfk_1' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_fk_drop_child" DROP CONSTRAINT "wptests_fk_drop_child_ibfk_1"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame(
			array( 'fk_drop_parent' ),
			array_values( array_unique( array_column( $this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_fk_drop_child' ), 'constraint_name' ) ) )
		);

		$show_create = $driver->query( 'SHOW CREATE TABLE wptests_fk_drop_child' );
		$this->assertStringContainsString( 'CONSTRAINT `fk_drop_parent` FOREIGN KEY', $show_create[0]->{'Create Table'} );
		$this->assertStringNotContainsString( 'wptests_fk_drop_child_ibfk_1', $show_create[0]->{'Create Table'} );

		$driver->query( 'ALTER TABLE wptests_fk_drop_child DROP FOREIGN KEY fk_drop_parent' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_fk_drop_child" DROP CONSTRAINT "fk_drop_parent"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$this->assertSame( array(), $this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_fk_drop_child' ) );

		$show_create = $driver->query( 'SHOW CREATE TABLE wptests_fk_drop_child' );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $show_create[0]->{'Create Table'} );
	}

	/**
	 * Tests ALTER TABLE ADD FULLTEXT/SPATIAL indexes update metadata without PostgreSQL index DDL.
	 */
	public function test_alter_table_add_fulltext_and_spatial_indexes_are_metadata_only(): void {
		$driver = $this->create_driver();

		$driver->query(
			'CREATE TABLE wptests_alter_search_geo (
				id int NOT NULL,
				body longtext NOT NULL,
				shape geometry NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_search_geo (
				id int NOT NULL,
				body longtext NOT NULL,
				shape geometry NOT NULL
			)'
		);

		$this->assertSame(
			0,
			$driver->query(
				'ALTER TABLE wptests_alter_search_geo
					ADD FULLTEXT KEY body_fulltext (body),
					ADD SPATIAL INDEX shape_spatial (shape)'
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_alter_search_geo' );
		$this->assertSame( array( 'body_fulltext', 'shape_spatial' ), array_column( $indexes, 'key_name' ) );
		$this->assertSame( array( 'FULLTEXT', 'SPATIAL' ), array_column( $indexes, 'index_type' ) );
		$this->assertNull( $indexes[0]['sub_part'] );
		$this->assertSame( '32', $indexes[1]['sub_part'] );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_alter_search_geo' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  SPATIAL KEY `shape_spatial` (`shape`(32))', $create_table );
		$this->assertStringContainsString( '  FULLTEXT KEY `body_fulltext` (`body`)', $create_table );
	}

	/**
	 * Tests ALTER TABLE DROP COLUMN removes column and dependent index metadata.
	 */
	public function test_alter_table_drop_column_updates_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_drop (
				id int(11) NOT NULL,
				status varchar(20) DEFAULT 'draft',
				obsolete varchar(20) DEFAULT NULL,
				PRIMARY KEY (id),
				KEY obsolete_idx (obsolete)
			)"
		);

		$driver->query( 'ALTER TABLE wptests_plugin_drop DROP obsolete' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_plugin_drop" DROP COLUMN "obsolete"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_drop' );
		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_plugin_drop' );

		$this->assertSame( array( 'id', 'status' ), array_column( $columns, 'column_name' ) );
		$this->assertSame( array( 'PRIMARY' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
	}

	/**
	 * Tests ALTER TABLE DROP COLUMN preserves surviving composite secondary index parts.
	 */
	public function test_alter_table_drop_column_preserves_composite_secondary_index_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_drop_composite (
				id int(11) NOT NULL,
				first_key varchar(20) NOT NULL,
				obsolete varchar(20) DEFAULT NULL,
				last_key varchar(20) NOT NULL,
				PRIMARY KEY (id),
				KEY combo_idx (first_key, obsolete, last_key),
				KEY obsolete_idx (obsolete)
			)"
		);

		$driver->query( 'ALTER TABLE wptests_plugin_drop_composite DROP COLUMN obsolete' );

		$indexes         = $this->get_mysql_index_metadata_rows( $driver, 'wptests_plugin_drop_composite' );
		$composite_index = array_values(
			array_filter(
				$indexes,
				static function ( $row ): bool {
					return 'combo_idx' === $row['key_name'];
				}
			)
		);

		$this->assertSame( array( 'PRIMARY', 'combo_idx' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );
		$this->assertSame( array( 'first_key', 'last_key' ), array_column( $composite_index, 'column_name' ) );
		$this->assertSame( array( '1', '2' ), array_map( 'strval', array_column( $composite_index, 'seq_in_index' ) ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_plugin_drop_composite' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  KEY `combo_idx` (`first_key`, `last_key`)', $create_table );
		$this->assertStringNotContainsString( '`obsolete`', $create_table );
		$this->assertStringNotContainsString( 'obsolete_idx', $create_table );

		$show_index = $driver->query( "SHOW INDEX FROM wptests_plugin_drop_composite WHERE Key_name = 'combo_idx'" );
		$this->assertCount( 2, $show_index );
		$this->assertSame( 'first_key', $show_index[0]->Column_name );
		$this->assertSame( '1', $show_index[0]->Seq_in_index );
		$this->assertSame( 'last_key', $show_index[1]->Column_name );
		$this->assertSame( '2', $show_index[1]->Seq_in_index );
	}

	/**
	 * Tests ALTER TABLE RENAME COLUMN updates backend and MySQL metadata.
	 */
	public function test_alter_table_rename_column_updates_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_column_parent (
				id int(11) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_column (
				id int(11) NOT NULL,
				old_parent int(11) NOT NULL,
				status varchar(20) DEFAULT "draft",
				PRIMARY KEY (id),
				KEY old_parent_idx (old_parent)
			)'
		);
		$driver->query( 'ALTER TABLE wptests_rename_column ADD CONSTRAINT fk_old_parent FOREIGN KEY (old_parent) REFERENCES wptests_rename_column_parent (id)' );

		$driver->query( 'ALTER TABLE wptests_rename_column RENAME COLUMN `old_parent` TO `parent_id`' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_rename_column" RENAME COLUMN "old_parent" TO "parent_id"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns      = $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_column' );
		$indexes      = $this->get_mysql_index_metadata_rows( $driver, 'wptests_rename_column' );
		$foreign_keys = $this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_rename_column' );
		$renamed_key  = array_values(
			array_filter(
				$indexes,
				static function ( array $index ): bool {
					return 'old_parent_idx' === $index['key_name'];
				}
			)
		);

		$this->assertSame( array( 'id', 'parent_id', 'status' ), array_column( $columns, 'column_name' ) );
		$this->assertSame( array( 'parent_id' ), array_column( $renamed_key, 'column_name' ) );
		$this->assertSame( array( 'parent_id' ), array_values( array_unique( array_column( $foreign_keys, 'column_name' ) ) ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_rename_column' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  `parent_id` int(11) NOT NULL,', $create_table );
		$this->assertStringContainsString( '  KEY `old_parent_idx` (`parent_id`)', $create_table );
		$this->assertStringContainsString( '  CONSTRAINT `fk_old_parent` FOREIGN KEY (`parent_id`) REFERENCES `wptests_rename_column_parent` (`id`)', $create_table );
		$this->assertStringNotContainsString( '`old_parent`', $create_table );
	}

	/**
	 * Tests ALTER TABLE RENAME COLUMN updates foreign keys that reference the renamed column.
	 */
	public function test_alter_table_rename_referenced_column_updates_foreign_key_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_ref_parent (
				id int(11) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_ref_child (
				id int(11) NOT NULL,
				parent_id int(11) NOT NULL,
				PRIMARY KEY (id),
				KEY parent_id (parent_id)
			)'
		);
		$driver->query( 'ALTER TABLE wptests_rename_ref_child ADD CONSTRAINT fk_rename_ref_parent FOREIGN KEY (parent_id) REFERENCES wptests_rename_ref_parent (id)' );

		$driver->query( 'ALTER TABLE wptests_rename_ref_parent RENAME COLUMN id TO parent_pk' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_rename_ref_parent" RENAME COLUMN "id" TO "parent_pk"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$foreign_keys = $this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_rename_ref_child' );
		$this->assertSame( array( 'parent_pk' ), array_values( array_unique( array_column( $foreign_keys, 'referenced_column_name' ) ) ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_rename_ref_child' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  CONSTRAINT `fk_rename_ref_parent` FOREIGN KEY (`parent_id`) REFERENCES `wptests_rename_ref_parent` (`parent_pk`)', $create_table );
		$this->assertStringNotContainsString( 'REFERENCES `wptests_rename_ref_parent` (`id`)', $create_table );
	}

	/**
	 * Tests ALTER TABLE RENAME INDEX updates backend and MySQL metadata.
	 */
	public function test_alter_table_rename_index_updates_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_rename_index (
				id int(11) NOT NULL,
				slug varchar(191) NOT NULL DEFAULT '',
				status varchar(20) DEFAULT 'draft',
				PRIMARY KEY (id),
				KEY old_slug_idx (slug)
			)"
		);

		$driver->query( 'ALTER TABLE wptests_rename_index RENAME INDEX `old_slug_idx` TO `new_slug_idx`' );

		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER INDEX "wptests_rename_index__old_slug_idx" RENAME TO "wptests_rename_index__new_slug_idx"',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$indexes = $this->get_mysql_index_metadata_rows( $driver, 'wptests_rename_index' );
		$this->assertSame( array( 'PRIMARY', 'new_slug_idx' ), array_values( array_unique( array_column( $indexes, 'key_name' ) ) ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_rename_index' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  KEY `new_slug_idx` (`slug`)', $create_table );
		$this->assertStringNotContainsString( 'old_slug_idx', $create_table );
	}

	/**
	 * Tests ALTER TABLE RENAME INDEX validates stored MySQL metadata before backend execution.
	 */
	public function test_alter_table_rename_index_fails_closed_for_missing_or_duplicate_metadata(): void {
		$queries = array(
			'ALTER TABLE wptests_rename_index_guard RENAME INDEX missing_idx TO new_slug_idx',
			'ALTER TABLE wptests_rename_index_guard RENAME INDEX old_slug_idx TO existing_slug_idx',
			'ALTER TABLE wptests_rename_index_guard RENAME INDEX PRIMARY TO renamed_primary',
		);

		foreach ( $queries as $query ) {
			$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
			$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
			$this->install_information_schema_fixture( $driver );
			$driver->store_mysql_schema_metadata(
				"CREATE TABLE wptests_rename_index_guard (
					id int(11) NOT NULL,
					slug varchar(191) NOT NULL DEFAULT '',
					status varchar(20) DEFAULT 'draft',
					PRIMARY KEY (id),
					KEY old_slug_idx (slug),
					KEY existing_slug_idx (status)
				)"
			);

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported ALTER TABLE statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported ALTER TABLE statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests ALTER TABLE RENAME KEY accepts KEY syntax and metadata-only index types.
	 */
	public function test_alter_table_rename_key_accepts_metadata_only_indexes(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_metadata_index (
				id int(11) NOT NULL,
				body text NOT NULL,
				PRIMARY KEY (id),
				FULLTEXT KEY old_body_idx (body)
			)'
		);

		$driver->query( 'ALTER TABLE wptests_rename_metadata_index RENAME KEY old_body_idx TO new_body_idx' );

		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$indexes     = $this->get_mysql_index_metadata_rows( $driver, 'wptests_rename_metadata_index' );
		$renamed_key = array_values(
			array_filter(
				$indexes,
				static function ( array $index ): bool {
					return 'new_body_idx' === $index['key_name'];
				}
			)
		);
		$this->assertSame( array( 'new_body_idx' ), array_column( $renamed_key, 'key_name' ) );
		$this->assertSame( array( 'FULLTEXT' ), array_column( $renamed_key, 'index_type' ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_rename_metadata_index' )[0]->{'Create Table'};
		$this->assertStringContainsString( '  FULLTEXT KEY `new_body_idx` (`body`)', $create_table );
		$this->assertStringNotContainsString( 'old_body_idx', $create_table );
	}

	/**
	 * Tests ALTER TABLE RENAME TO updates backend and MySQL metadata.
	 */
	public function test_alter_table_rename_to_updates_backend_and_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_rename_table_old (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_table_old (
				id int(11) NOT NULL,
				name varchar(20) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO wptests_rename_table_old (id, name) VALUES (1, 'before')" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_rename_table_old RENAME TO wptests_rename_table_new' ) );

		$rows = $driver->query( 'SELECT id, name FROM wptests_rename_table_new' );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'before', $rows[0]->name );
		$this->assertSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_table_old' ) );
		$this->assertSame( array( 'id', 'name' ), array_column( $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_table_new' ), 'column_name' ) );

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_rename_table_new' )[0]->{'Create Table'};
		$this->assertStringStartsWith( "CREATE TABLE `wptests_rename_table_new` (\n", $create_table );
		$this->assertStringNotContainsString( 'wptests_rename_table_old', $create_table );
	}

	/**
	 * Tests RENAME TABLE updates table, index, and foreign-key metadata.
	 */
	public function test_rename_table_updates_indexes_and_foreign_key_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_parent (
				id int(11) NOT NULL,
				slug varchar(20) NOT NULL,
				PRIMARY KEY (id),
				KEY slug_idx (slug)
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_rename_child (
				id int(11) NOT NULL,
				parent_id int(11) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query(
			'ALTER TABLE wptests_rename_child
				ADD CONSTRAINT parent_fk FOREIGN KEY (parent_id) REFERENCES wptests_rename_parent (id)'
		);
		$driver->query( 'CREATE INDEX standalone_slug ON wptests_rename_parent (slug)' );

		$this->assertSame( 0, $driver->query( 'RENAME TABLE wptests_rename_parent TO wptests_renamed_parent' ) );

		$sql = array_column( $driver->get_last_postgresql_queries(), 'sql' );
		$this->assertContains( 'ALTER TABLE "wptests_rename_parent" RENAME TO "wptests_renamed_parent"', $sql );
		$this->assertContains( 'ALTER INDEX "wptests_rename_parent__slug_idx" RENAME TO "wptests_renamed_parent__slug_idx"', $sql );
		$this->assertContains( 'ALTER INDEX "wptests_rename_parent__standalone_slug" RENAME TO "wptests_renamed_parent__standalone_slug"', $sql );

		$this->assertSame( array(), $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_parent' ) );
		$this->assertSame( array( 'id', 'slug' ), array_column( $this->get_mysql_column_metadata_rows( $driver, 'wptests_renamed_parent' ), 'column_name' ) );
		$this->assertSame( array( 'PRIMARY', 'slug_idx', 'standalone_slug' ), array_column( $this->get_mysql_index_metadata_rows( $driver, 'wptests_renamed_parent' ), 'key_name' ) );

		$child_foreign_keys = $this->get_mysql_foreign_key_metadata_rows( $driver, 'wptests_rename_child' );
		$this->assertSame( array( 'wptests_renamed_parent' ), array_values( array_unique( array_column( $child_foreign_keys, 'referenced_table_name' ) ) ) );
	}

	/**
	 * Tests ALTER TABLE RENAME AS and bare RENAME forms are accepted.
	 */
	public function test_alter_table_rename_as_and_bare_rename_forms_update_metadata(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_rename_as_old (id INTEGER NOT NULL PRIMARY KEY)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_rename_as_old (id int(11) NOT NULL, PRIMARY KEY (id))' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_rename_as_old RENAME AS wptests_rename_as_new' ) );
		$this->assertSame( array( 'id' ), array_column( $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_as_new' ), 'column_name' ) );

		$driver->query( 'CREATE TABLE wptests_rename_bare_old (id INTEGER NOT NULL PRIMARY KEY)' );
		$driver->store_mysql_schema_metadata( 'CREATE TABLE wptests_rename_bare_old (id int(11) NOT NULL, PRIMARY KEY (id))' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_rename_bare_old RENAME wptests_rename_bare_new' ) );
		$this->assertSame( array( 'id' ), array_column( $this->get_mysql_column_metadata_rows( $driver, 'wptests_rename_bare_new' ), 'column_name' ) );
	}

	/**
	 * Tests unsupported RENAME TABLE variants fail before backend execution.
	 */
	public function test_unsupported_rename_table_variants_do_not_reach_backend(): void {
		$queries = array(
			'RENAME TABLE wptests_old_name TO wptests_new_name, wptests_old_two TO wptests_new_two',
			'RENAME TABLE wptests_old_name TO other_db.wptests_new_name',
			'RENAME TABLE information_schema.tables TO wptests_tables',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported RENAME TABLE statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertContains(
					$e->getMessage(),
					array( 'Unsupported RENAME TABLE statement.', 'Unsupported information_schema query.' ),
					$query
				);
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests ALTER COLUMN DROP DEFAULT updates backend and MySQL metadata.
	 */
	public function test_alter_table_drop_default_updates_backend_and_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_defaults (
				id int(11) NOT NULL,
				status varchar(20) DEFAULT 'draft',
				PRIMARY KEY (id)
			)"
		);

		$driver->query( 'ALTER TABLE wptests_plugin_defaults ALTER COLUMN status DROP DEFAULT' );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_plugin_defaults" ALTER COLUMN "status" DROP DEFAULT',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_defaults' );

		$this->assertNull( $columns[1]['column_default'] );
	}

	/**
	 * Tests MySQL table-option ALTER clauses are supported no-ops.
	 */
	public function test_alter_table_storage_options_are_supported_noops(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_options (
				id int(11) NOT NULL,
				status varchar(20) DEFAULT 'draft',
				PRIMARY KEY (id)
			)"
		);

		$columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_options' );

		$this->assertSame(
			0,
			$driver->query(
				'ALTER TABLE wptests_plugin_options
					ENGINE=InnoDB,
					DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci,
					DEFAULT COLLATE=utf8mb4_unicode_ci,
					ROW_FORMAT=DYNAMIC'
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_options' ) );
	}

	/**
	 * Tests MySQL key-maintenance ALTER clauses are supported no-ops.
	 */
	public function test_alter_table_key_maintenance_clauses_are_supported_noops(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection();
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_plugin_keys (
				id int(11) NOT NULL,
				status varchar(20) DEFAULT 'draft',
				PRIMARY KEY (id),
				KEY status (status)
			)"
		);

		$columns_before = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_keys' );
		$create_before  = $driver->query( 'SHOW CREATE TABLE wptests_plugin_keys' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_plugin_keys DISABLE KEYS, ENABLE KEYS' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( $columns_before, $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_keys' ) );

		$create_after = $driver->query( 'SHOW CREATE TABLE wptests_plugin_keys' );
		$this->assertSame( $create_before[0]->{'Create Table'}, $create_after[0]->{'Create Table'} );

		$this->assertSame( 1, $driver->query( 'ALTER TABLE wptests_plugin_keys DISABLE KEYS, ADD COLUMN note varchar(20), ENABLE KEYS' ) );
		$this->assertSame(
			array(
				array(
					'sql'    => 'ALTER TABLE "wptests_plugin_keys" ADD COLUMN "note" varchar(20)',
					'params' => array(),
				),
			),
			$driver->get_last_postgresql_queries()
		);

		$columns_after = $this->get_mysql_column_metadata_rows( $driver, 'wptests_plugin_keys' );
		$this->assertCount( 3, $columns_after );
		$this->assertSame( 'note', $columns_after[2]['column_name'] );
	}

	/**
	 * Tests ALTER TABLE AUTO_INCREMENT adjusts the SQLite-backed test sequence.
	 */
	public function test_alter_table_auto_increment_updates_sqlite_sequence(): void {
		$driver = $this->create_driver();
		$driver->query(
			'CREATE TABLE wptests_alter_auto_increment (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_auto_increment (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) DEFAULT NULL,
				PRIMARY KEY (id)
			)'
		);

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_auto_increment AUTO_INCREMENT = 50' ) );
		$this->assertSame( 1, $driver->query( "INSERT INTO wptests_alter_auto_increment (name) VALUES ('first')" ) );

		$rows = $driver->query( 'SELECT id, name FROM wptests_alter_auto_increment' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '50', $rows[0]->id );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_auto_increment AUTO_INCREMENT = 1' ) );
		$this->assertSame( 1, $driver->query( "INSERT INTO wptests_alter_auto_increment (name) VALUES ('second')" ) );

		$rows = $driver->query( 'SELECT id, name FROM wptests_alter_auto_increment ORDER BY id' );
		$this->assertSame( '50', $rows[0]->id );
		$this->assertSame( '51', $rows[1]->id );

		$driver->query( 'CREATE TABLE wptests_alter_auto_increment_plain (id INTEGER, name TEXT)' );
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_alter_auto_increment_plain (
				id int(11) DEFAULT NULL,
				name varchar(191) DEFAULT NULL
			)'
		);

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_alter_auto_increment_plain AUTO_INCREMENT = 500' ) );
	}

	/**
	 * Tests ALTER TABLE AUTO_INCREMENT emits guarded PostgreSQL sequence repair.
	 */
	public function test_alter_table_auto_increment_uses_postgresql_identity_metadata(): void {
		$connection = new WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection(
			$this->get_dml_identity_metadata_fixture( 'wptests_pg_alter_auto_increment', 'id', 'wptests_pg_alter_auto_increment_id_seq' )
		);
		$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$driver->query(
			'CREATE TABLE wptests_pg_alter_auto_increment (
				id INTEGER PRIMARY KEY,
				name TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_pg_alter_auto_increment (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) DEFAULT NULL,
				PRIMARY KEY (id)
			)'
		);

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_pg_alter_auto_increment AUTO_INCREMENT = 200' ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( array(), $queries[0]['params'] );
		$this->assertStringContainsString( 'pg_catalog.setval(CAST(', $queries[0]['sql'] );
		$this->assertStringContainsString( 'wptests_pg_alter_auto_increment_id_seq', $queries[0]['sql'] );
		$this->assertStringContainsString( 'GREATEST(COALESCE(MAX("id"), 0), 199)', $queries[0]['sql'] );
		$this->assertStringContainsString( 'table_state.max_identity_value > 0', $queries[0]['sql'] );
		$this->assertSame( 1, $connection->get_sequence_sync_query_count() );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE wptests_pg_alter_auto_increment AUTO_INCREMENT 300' ) );
		$this->assertStringContainsString( 'GREATEST(COALESCE(MAX("id"), 0), 299)', $driver->get_last_postgresql_queries()[0]['sql'] );
		$this->assertSame( 2, $connection->get_sequence_sync_query_count() );
	}

	/**
	 * Tests SHOW COLUMNS WHERE exact filters catalog rows with bound parameters.
	 */
	public function test_show_columns_where_exact_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW COLUMNS FROM wptests_options WHERE Field = 'option_name'" );

		$this->assertCount( 1, $result );
		$this->assertSame( 'option_name', $result[0]->Field );
		$this->assertSame( 'varchar(191)', $result[0]->Type );
		$this->assertSame( 6, $driver->get_last_column_count() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW COLUMNS', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_name' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW FIELDS WHERE exact filters use the SHOW COLUMNS parser.
	 */
	public function test_show_fields_where_exact_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW FIELDS FROM wptests_options WHERE Type = 'varchar(191)'" );

		$this->assertCount( 1, $result );
		$this->assertSame( 'option_name', $result[0]->Field );
		$this->assertSame( 'varchar(191)', $result[0]->Type );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'column_type = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW FIELDS', strtoupper( $queries[0]['sql'] ) );
		$this->assertSame( array( 'public', 'wptests_options', 'varchar(191)' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW FULL COLUMNS WHERE exact filters full catalog rows.
	 */
	public function test_show_full_columns_where_exact_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW FULL COLUMNS FROM wptests_options WHERE Field = 'option_name'" );

		$this->assertCount( 1, $result );
		$this->assertSame( 'option_name', $result[0]->Field );
		$this->assertSame( 'utf8mb4_unicode_ci', $result[0]->Collation );
		$this->assertSame( 9, $driver->get_last_column_count() );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW FULL COLUMNS', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_name' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW COLUMNS/FIELDS WHERE LIKE filters catalog rows with bound parameters.
	 */
	public function test_show_columns_where_like_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW COLUMNS FROM wptests_options WHERE Field LIKE 'option_%'" );

		$this->assertSame(
			array( 'option_id', 'option_name', 'option_value' ),
			array_map(
				static function ( $row ): string {
					return $row->Field;
				},
				$result
			)
		);
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'field_name LIKE ?', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_%' ), $queries[0]['params'] );

		$result = $driver->query( "SHOW FIELDS FROM wptests_options WHERE Type LIKE 'varchar%'" );

		$this->assertSame(
			array( 'option_name', 'autoload' ),
			array_map(
				static function ( $row ): string {
					return $row->Field;
				},
				$result
			)
		);
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'column_type LIKE ?', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'varchar%' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW COLUMNS/FIELDS WHERE filters support AND-combined predicates.
	 */
	public function test_show_columns_where_and_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW COLUMNS FROM wptests_options WHERE Field LIKE 'option_%' AND Type = 'varchar(191)'" );

		$this->assertSame(
			array( 'option_name' ),
			array_map(
				static function ( $row ): string {
					return $row->Field;
				},
				$result
			)
		);

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "field_name LIKE ? ESCAPE '\\'", $queries[0]['sql'] );
		$this->assertStringContainsString( 'column_type = ?', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_%', 'varchar(191)' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW COLUMNS/FIELDS WHERE expression filters catalog rows after fetching.
	 */
	public function test_show_columns_where_expression_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$result = $driver->query( "SHOW COLUMNS FROM wptests_options WHERE Field <> 'option_name'" );

		$this->assertSame(
			array( 'option_id', 'option_value', 'autoload' ),
			array_map(
				static function ( $row ): string {
					return $row->Field;
				},
				$result
			)
		);

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
		$this->assertStringNotContainsString( 'field_name <>', $queries[0]['sql'] );

		$result = $driver->query( "SHOW FIELDS FROM wptests_options WHERE Field = 'option_name' OR Type = 'text'" );

		$this->assertSame(
			array( 'option_name', 'option_value' ),
			array_map(
				static function ( $row ): string {
					return $row->Field;
				},
				$result
			)
		);
		$this->assertSame( array( 'public', 'wptests_options' ), $driver->get_last_postgresql_queries()[0]['params'] );
	}

	/**
	 * Tests unsupported SHOW COLUMNS/FIELDS WHERE forms do not reach the backend.
	 */
	public function test_show_columns_where_unsupported_forms_do_not_reach_backend(): void {
		$queries = array(
			'SHOW COLUMNS FROM wptests_options WHERE Field = option_name',
			'SHOW COLUMNS FROM wptests_options WHERE Field LIKE option_%',
			"SHOW COLUMNS FROM wptests_options WHERE Unknown = 'option_name'",
			"SHOW FIELDS FROM wptests_options WHERE Privileges = 'select,insert,update,references'",
			"SHOW COLUMNS FROM wptests_options LIKE 'option_%' WHERE Field = 'option_name'",
			"SHOW FIELDS FROM wptests_options LIKE 'option_%' WHERE Field = 'option_name'",
			"SHOW FULL COLUMNS FROM wptests_options LIKE 'option_%' WHERE Field = 'option_name'",
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW COLUMNS statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW COLUMNS statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
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
	 * Tests SHOW TABLES accepts current database qualification forms.
	 */
	public function test_show_tables_accepts_current_database_qualification_forms(): void {
		$cases = array(
			'SHOW TABLES FROM wptests'                  => array( 'Tables_in_wptests', 3, array( 'public' ) ),
			"SHOW TABLES IN `wptests` LIKE 'wptests_%'" => array( 'Tables_in_wptests', 3, array( 'public', 'wptests_%' ) ),
			"SHOW FULL TABLES FROM wptests LIKE 'wptests_%'" => array( 'Tables_in_wptests', 3, array( 'public', 'wptests_%' ) ),
		);

		foreach ( $cases as $query => $expected ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			$tables = $driver->query( $query );

			$this->assertCount( $expected[1], $tables, $query );
			$this->assertSame( $expected[0], $driver->get_last_column_meta()[0]['name'], $query );
			$this->assertSame( 'wptests_options', $tables[0]->{$expected[0]}, $query );

			$queries = $driver->get_last_postgresql_queries();
			$this->assertCount( 1, $queries, $query );
			$this->assertStringNotContainsString( 'SHOW TABLES', $queries[0]['sql'], $query );
			$this->assertSame( $expected[2], $queries[0]['params'], $query );
		}
	}

	/**
	 * Tests SHOW TABLES WHERE exact filters catalog rows with bound parameters.
	 */
	public function test_show_tables_where_exact_filters_catalog_rows(): void {
		$cases = array(
			"SHOW TABLES WHERE Tables_in_wptests = 'wptests_options'" => array(
				'Tables_in_wptests',
				1,
				array( 'public', 'wptests_options' ),
			),
			"SHOW TABLES FROM wptests WHERE Tables_in_wptests = 'wptests_options'" => array(
				'Tables_in_wptests',
				1,
				array( 'public', 'wptests_options' ),
			),
			"SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" => array(
				'Tables_in_wptests',
				2,
				array( 'public', 'BASE TABLE' ),
			),
			"SHOW FULL TABLES FROM wptests WHERE Tables_in_wptests = 'wptests_options'" => array(
				'Tables_in_wptests',
				1,
				array( 'public', 'wptests_options' ),
			),
		);

		foreach ( $cases as $query => $expected ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			$tables = $driver->query( $query );

			$this->assertCount( $expected[1], $tables, $query );
			$this->assertSame( $expected[0], $driver->get_last_column_meta()[0]['name'], $query );
			$this->assertSame( 'wptests_options', $tables[0]->{$expected[0]}, $query );

			$queries = $driver->get_last_postgresql_queries();
			$this->assertCount( 1, $queries, $query );
			$this->assertStringNotContainsString( 'SHOW TABLES', $queries[0]['sql'], $query );
			$this->assertSame( $expected[2], $queries[0]['params'], $query );
		}
	}

	/**
	 * Tests SHOW TABLES WHERE LIKE filters catalog rows with bound parameters.
	 */
	public function test_show_tables_where_like_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( "SHOW TABLES WHERE Tables_in_wptests LIKE 'wptests_%'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts', 'wptests_view' ),
			array_map(
				static function ( $row ): string {
					return $row->Tables_in_wptests;
				},
				$tables
			)
		);
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'table_name LIKE ?', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_%' ), $queries[0]['params'] );

		$full_tables = $driver->query( "SHOW FULL TABLES WHERE Table_type LIKE 'BASE%'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts' ),
			array_map(
				static function ( $row ): string {
					return $row->Tables_in_wptests;
				},
				$full_tables
			)
		);
		$this->assertSame( array( 'BASE TABLE', 'BASE TABLE' ), array( $full_tables[0]->Table_type, $full_tables[1]->Table_type ) );
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "CASE WHEN table_type = 'VIEW' THEN 'VIEW' ELSE 'BASE TABLE' END LIKE ?", $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'BASE%' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW FULL TABLES WHERE filters support AND-combined predicates.
	 */
	public function test_show_full_tables_where_and_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( "SHOW FULL TABLES WHERE Tables_in_wptests LIKE 'wptests_%' AND Table_type = 'BASE TABLE'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts' ),
			array_map(
				static function ( $row ): string {
					return $row->Tables_in_wptests;
				},
				$tables
			)
		);
		$this->assertSame( array( 'BASE TABLE', 'BASE TABLE' ), array( $tables[0]->Table_type, $tables[1]->Table_type ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "table_name LIKE ? ESCAPE '\\'", $queries[0]['sql'] );
		$this->assertStringContainsString( "CASE WHEN table_type = 'VIEW' THEN 'VIEW' ELSE 'BASE TABLE' END = ?", $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_%', 'BASE TABLE' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW TABLES WHERE expression filters catalog rows after fetching.
	 */
	public function test_show_full_tables_where_expression_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( "SHOW FULL TABLES WHERE Table_type <> 'VIEW'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_posts' ),
			array_map(
				static function ( $row ): string {
					return $row->Tables_in_wptests;
				},
				$tables
			)
		);
		$this->assertSame( array( 'BASE TABLE', 'BASE TABLE' ), array( $tables[0]->Table_type, $tables[1]->Table_type ) );
		$this->assertSame( array( 'public' ), $driver->get_last_postgresql_queries()[0]['params'] );

		$tables = $driver->query( "SHOW FULL TABLES WHERE LEFT(Tables_in_wptests, 8) = 'wptests_' AND Table_type = 'VIEW'" );

		$this->assertSame( array( 'wptests_view' ), array( $tables[0]->Tables_in_wptests ) );
		$this->assertSame( 'VIEW', $tables[0]->Table_type );
		$this->assertSame( array( 'public' ), $driver->get_last_postgresql_queries()[0]['params'] );
	}

	/**
	 * Tests unsupported SHOW TABLES database qualifiers fail before backend execution.
	 */
	public function test_show_tables_unsupported_database_qualification_does_not_reach_backend(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'SHOW TABLES FROM other_db' );
			$this->fail( 'Expected unsupported SHOW TABLES statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported SHOW TABLES statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests unsupported SHOW TABLES WHERE forms do not reach the backend.
	 */
	public function test_show_tables_where_unsupported_forms_do_not_reach_backend(): void {
		$queries = array(
			"SHOW TABLES WHERE Table_type = 'BASE TABLE'",
			'SHOW TABLES WHERE Tables_in_wptests LIKE wptests_%',
			'SHOW TABLES WHERE Tables_in_wptests = wptests_options',
			"SHOW TABLES WHERE Unknown = 'wptests_options'",
			"SHOW TABLES WHERE Tables_in_wptests = 'wptests_options' AND Table_type = 'BASE TABLE'",
			"SHOW TABLES WHERE Tables_in_wptests LIKE 'wptests_%' AND Table_type = 'BASE TABLE'",
			"SHOW TABLES LIKE 'wptests_%' WHERE Tables_in_wptests = 'wptests_options'",
			"SHOW FULL TABLES LIKE 'wptests_%' WHERE Table_type = 'BASE TABLE'",
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$this->install_information_schema_fixture( $driver );

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW TABLES statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW TABLES statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
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
	 * Tests SHOW TABLE STATUS WHERE filters match materialized output columns.
	 */
	public function test_show_table_status_where_exact_filters_output_columns(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_show_table_status_auto_increment_fixture( $driver );

		$tables = $driver->query( "SHOW TABLE STATUS WHERE Name = 'wptests_options'" );

		$this->assertSame( array( 'wptests_options' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );

		$tables = $driver->query( 'SHOW TABLE STATUS WHERE Version = 10' );

		$this->assertSame(
			array( 'wptests_options', 'wptests_plain', 'wptests_posts' ),
			array_map( array( $this, 'get_show_table_status_row_name' ), $tables )
		);

		$tables = $driver->query( 'SHOW TABLE STATUS WHERE Auto_increment = 6' );

		$this->assertSame( array( 'wptests_posts' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );
		$this->assertSame( '6', $tables[0]->Auto_increment );

		$tables = $driver->query( 'SHOW TABLE STATUS WHERE Auto_increment >= 1' );

		$this->assertSame( array( 'wptests_options', 'wptests_posts' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );

		$tables = $driver->query( "SHOW TABLE STATUS WHERE Name = 'wptests_posts' OR Auto_increment IS NULL" );

		$this->assertSame( array( 'wptests_plain', 'wptests_posts' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );

		$tables = $driver->query( "SHOW TABLE STATUS WHERE SUBSTR(Name, 9, 7) = 'options'" );

		$this->assertSame( array( 'wptests_options' ), array_map( array( $this, 'get_show_table_status_row_name' ), $tables ) );

		$tables = $driver->query( "SHOW TABLE STATUS WHERE Name LIKE 'wptests_%'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_plain', 'wptests_posts' ),
			array_map( array( $this, 'get_show_table_status_row_name' ), $tables )
		);

		$tables = $driver->query( "SHOW TABLE STATUS WHERE Name LIKE 'wptests_%' AND Engine = 'InnoDB'" );

		$this->assertSame(
			array( 'wptests_options', 'wptests_plain', 'wptests_posts' ),
			array_map( array( $this, 'get_show_table_status_row_name' ), $tables )
		);
	}

	/**
	 * Tests unsupported SHOW TABLE STATUS WHERE clauses fail before backend execution.
	 */
	public function test_unsupported_show_table_status_where_clause_does_not_reach_backend(): void {
		$unsupported_queries = array(
			'SHOW TABLE STATUS WHERE Name LIKE wptests_%',
			'SHOW TABLE STATUS WHERE Name = wptests_options',
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
		$this->assertCount( 4, $queries );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_COLUMN_METADATA_TABLE, $queries[0]['sql'] );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_INDEX_METADATA_TABLE, $queries[1]['sql'] );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_FOREIGN_KEY_METADATA_TABLE, $queries[2]['sql'] );
		$this->assertStringContainsString( WP_PostgreSQL_Driver::MYSQL_TABLE_METADATA_TABLE, $queries[3]['sql'] );
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
	 * Tests SHOW CREATE TABLE includes CHECK constraints from PostgreSQL catalogs.
	 */
	public function test_show_create_table_includes_check_constraints(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_status varchar(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			)"
		);

		$tables       = $driver->query( 'SHOW CREATE TABLE wptests_posts' );
		$create_table = $tables[0]->{'Create Table'};

		$this->assertStringContainsString(
			'  CONSTRAINT `wptests_posts_status_chk` CHECK (post_status IS NOT NULL)',
			$create_table
		);

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 5, $queries );
		$this->assertStringContainsString( 'information_schema.check_constraints', $queries[3]['sql'] );
	}

	/**
	 * Tests MySQL comments round-trip through PostgreSQL-backed introspection.
	 */
	public function test_mysql_comment_metadata_round_trips_through_introspection(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query(
			"CREATE TABLE wptests_comment_metadata (
				id int NOT NULL COMMENT 'Identifier',
				label varchar(50) DEFAULT NULL COMMENT \"Display label\",
				KEY label_lookup (label) COMMENT 'Lookup index'
			) COMMENT='Table note'"
		);
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO information_schema.tables
				(table_schema, table_name, table_type)
			VALUES
				('public', 'wptests_comment_metadata', 'BASE TABLE')"
		);

		$create_table = $driver->query( 'SHOW CREATE TABLE wptests_comment_metadata' )[0]->{'Create Table'};

		$this->assertStringContainsString( "  `id` int NOT NULL COMMENT 'Identifier'", $create_table );
		$this->assertStringContainsString( "  `label` varchar(50) DEFAULT NULL COMMENT 'Display label'", $create_table );
		$this->assertStringContainsString( "  KEY `label_lookup` (`label`) COMMENT 'Lookup index'", $create_table );
		$this->assertStringEndsWith( "COMMENT='Table note'", $create_table );

		$columns         = $driver->query( 'SHOW FULL COLUMNS FROM wptests_comment_metadata' );
		$column_comments = array();
		foreach ( $columns as $column ) {
			$column_comments[ $column->Field ] = $column->Comment;
		}
		$this->assertSame( 'Identifier', $column_comments['id'] );
		$this->assertSame( 'Display label', $column_comments['label'] );

		$filtered_columns = $driver->query( "SHOW FULL COLUMNS FROM wptests_comment_metadata WHERE Comment = 'Display label'" );
		$this->assertCount( 1, $filtered_columns );
		$this->assertSame( 'label', $filtered_columns[0]->Field );

		$indexes = $driver->query( "SHOW INDEX FROM wptests_comment_metadata WHERE Index_comment = 'Lookup index'" );
		$this->assertCount( 1, $indexes );
		$this->assertSame( 'label_lookup', $indexes[0]->Key_name );
		$this->assertSame( 'Lookup index', $indexes[0]->Index_comment );

		$status = $driver->query( "SHOW TABLE STATUS LIKE 'wptests_comment_metadata'" );
		$this->assertCount( 1, $status );
		$this->assertSame( 'Table note', $status[0]->Comment );

		$tables = $driver->query(
			"SELECT TABLE_COMMENT
			FROM information_schema.tables
			WHERE table_name = 'wptests_comment_metadata'"
		);
		$this->assertCount( 1, $tables );
		$this->assertSame( 'Table note', $tables[0]->TABLE_COMMENT );

		$information_schema_columns = $driver->query(
			"SELECT COLUMN_NAME, COLUMN_COMMENT
			FROM information_schema.columns
			WHERE table_name = 'wptests_comment_metadata'
			ORDER BY ORDINAL_POSITION"
		);
		$this->assertSame(
			array(
				array( 'id', 'Identifier' ),
				array( 'label', 'Display label' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->COLUMN_NAME, $row->COLUMN_COMMENT );
				},
				$information_schema_columns
			)
		);

		$statistics = $driver->query(
			"SELECT INDEX_NAME, INDEX_COMMENT
			FROM information_schema.statistics
			WHERE table_name = 'wptests_comment_metadata'"
		);
		$this->assertSame( array( 'label_lookup', 'Lookup index' ), array( $statistics[0]->INDEX_NAME, $statistics[0]->INDEX_COMMENT ) );
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
	 * Tests SHOW CREATE TABLE information_schema targets return computed metadata.
	 */
	public function test_show_create_table_information_schema_target_returns_computed_metadata(): void {
		$driver = $this->create_driver();

		$tables = $driver->query( 'SHOW CREATE TABLE information_schema.tables' );

		$this->assertCount( 1, $tables );
		$this->assertSame( 'tables', $tables[0]->Table );
		$this->assertStringStartsWith( "CREATE TEMPORARY TABLE `tables` (\n", $tables[0]->{'Create Table'} );
		$this->assertStringContainsString( '  `TABLE_SCHEMA` varchar(512) DEFAULT NULL', $tables[0]->{'Create Table'} );
		$this->assertStringContainsString( '  `TABLE_NAME` varchar(512) DEFAULT NULL', $tables[0]->{'Create Table'} );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
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
	 * Tests supported SHOW GRANTS FOR/USING forms return the static grants row.
	 */
	public function test_show_grants_for_and_using_forms_return_static_row(): void {
		$queries = array(
			'SHOW GRANTS FOR current_user();',
			'SHOW GRANTS FOR CURRENT_USER',
			'sHoW gRaNtS FoR CuRrEnT_UsEr()',
			'SHOW GRANTS FOR root',
			"SHOW GRANTS FOR 'root'@'localhost'",
			'SHOW GRANTS USING role1',
			'SHOW GRANTS FOR CURRENT_USER() USING role1',
			'SHOW GRANTS FOR u@h USING r1,r2',
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
	 * Tests malformed SHOW GRANTS syntax fails before backend execution.
	 */
	public function test_malformed_show_grants_syntax_fails_closed(): void {
		$queries = array(
			'SHOW GRANTS FOR',
			'SHOW GRANTS USING',
			'SHOW GRANTS FOR CURRENT_USER(1)',
			'SHOW GRANTS FOR root USING',
			"SHOW GRANTS FOR root USING role1 WHERE User = 'root'",
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
	 * Tests SHOW STATUS returns bounded MySQL-shaped status rows.
	 */
	public function test_show_status_returns_bounded_mysql_shaped_rows(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SHOW STATUS' );

		$status = array();
		foreach ( $rows as $row ) {
			$status[ $row->Variable_name ] = $row->Value;
		}

		$this->assertSame( '0', $status['Uptime'] );
		$this->assertSame( '1', $status['Threads_connected'] );
		$this->assertSame( '0', $status['Questions'] );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame( array( 'Variable_name', 'Value' ), array_column( $driver->get_last_column_meta(), 'name' ) );

		$threads = $driver->query( "SHOW GLOBAL STATUS LIKE 'Threads_%'" );
		$this->assertSame(
			array( 'Threads_cached', 'Threads_connected', 'Threads_created', 'Threads_running' ),
			array_map(
				static function ( $row ): string {
					return $row->Variable_name;
				},
				$threads
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$uptime = $driver->query( "SHOW SESSION STATUS WHERE Variable_name = 'Uptime'" );
		$this->assertCount( 1, $uptime );
		$this->assertSame( 'Uptime', $uptime[0]->Variable_name );
		$this->assertSame( '0', $uptime[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$handlers = $driver->query( "SHOW STATUS WHERE Variable_name LIKE 'Handler_read_%'" );
		$this->assertSame(
			array(
				'Handler_read_first',
				'Handler_read_key',
				'Handler_read_next',
				'Handler_read_prev',
				'Handler_read_rnd',
				'Handler_read_rnd_next',
			),
			array_map(
				static function ( $row ): string {
					return $row->Variable_name;
				},
				$handlers
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$zero_values = $driver->query( "SHOW STATUS WHERE Value = '0'" );
		$zero_names  = array_map(
			static function ( $row ): string {
				return $row->Variable_name;
			},
			$zero_values
		);
		$this->assertContains( 'Uptime', $zero_names );
		$this->assertContains( 'Questions', $zero_names );
		$this->assertNotContains( 'Threads_connected', $zero_names );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$running_threads = $driver->query( "SHOW STATUS WHERE Variable_name LIKE 'Threads_%' AND Value = '1'" );
		$this->assertSame(
			array(
				'Threads_connected',
				'Threads_created',
				'Threads_running',
			),
			array_map(
				static function ( $row ): string {
					return $row->Variable_name;
				},
				$running_threads
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$non_zero_values = $driver->query( "SHOW STATUS WHERE Value <> '0'" );
		$this->assertSame(
			array(
				'Connections',
				'Threads_connected',
				'Threads_created',
				'Threads_running',
			),
			array_map(
				static function ( $row ): string {
					return $row->Variable_name;
				},
				$non_zero_values
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$thread_or_one_values = $driver->query( "SHOW STATUS WHERE Variable_name LIKE 'Threads_%' OR Value = '1'" );
		$this->assertSame(
			array(
				'Connections',
				'Threads_cached',
				'Threads_connected',
				'Threads_created',
				'Threads_running',
			),
			array_map(
				static function ( $row ): string {
					return $row->Variable_name;
				},
				$thread_or_one_values
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '5', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests unsupported SHOW STATUS clauses fail before backend execution.
	 */
	public function test_unsupported_show_status_clauses_fail_closed(): void {
		$queries = array(
			"SHOW STATUS WHERE Unknown = '0'",
			'SHOW STATUS LIMIT 1',
			'SHOW STATUS LIKE Threads_%',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW STATUS statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW STATUS statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests SHOW WARNINGS/ERRORS expose empty MySQL-shaped diagnostics.
	 */
	public function test_show_warnings_and_errors_return_empty_mysql_shaped_diagnostics(): void {
		$driver = $this->create_driver();

		$warnings = $driver->query( 'SHOW WARNINGS' );
		$this->assertSame( array(), $warnings );
		$this->assertSame( array( 'Level', 'Code', 'Message' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '0', $found_rows[0]->{'FOUND_ROWS()'} );

		$errors = $driver->query( 'SHOW ERRORS LIMIT 0, 10', PDO::FETCH_ASSOC );
		$this->assertSame( array(), $errors );
		$this->assertSame( array( 'Level', 'Code', 'Message' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$limited_warnings = $driver->query( 'SHOW WARNINGS LIMIT 1 OFFSET 0' );
		$this->assertSame( array(), $limited_warnings );
		$this->assertSame( array( 'Level', 'Code', 'Message' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$warnings_count = $driver->query( 'SHOW COUNT(*) WARNINGS' );
		$this->assertSame( '0', $warnings_count[0]->{'@@session.warning_count'} );
		$this->assertSame( array( '@@session.warning_count' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$errors_count = $driver->query( 'SHOW COUNT(*) ERRORS', PDO::FETCH_NUM );
		$this->assertSame( array( array( '0' ) ), $errors_count );
		$this->assertSame( array( '@@session.error_count' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests unsupported SHOW WARNINGS/ERRORS clauses fail before backend execution.
	 */
	public function test_unsupported_show_warnings_and_errors_clauses_fail_closed(): void {
		$queries = array(
			"SHOW WARNINGS WHERE Level = 'Warning'" => 'Unsupported SHOW WARNINGS statement.',
			'SHOW WARNINGS LIMIT bad'               => 'Unsupported SHOW WARNINGS statement.',
			'SHOW WARNINGS LIMIT 1, bad'            => 'Unsupported SHOW WARNINGS statement.',
			'SHOW COUNT(*) WARNINGS LIMIT 1'        => 'Unsupported SHOW WARNINGS statement.',
			"SHOW ERRORS LIKE 'error%'"             => 'Unsupported SHOW ERRORS statement.',
			'SHOW ERRORS LIMIT bad'                 => 'Unsupported SHOW ERRORS statement.',
			'SHOW COUNT(*) ERRORS LIMIT 1'          => 'Unsupported SHOW ERRORS statement.',
		);

		foreach ( $queries as $query => $message ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW diagnostics statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests SHOW PROCESSLIST returns a bounded current-session row.
	 */
	public function test_show_processlist_returns_bounded_current_session_row(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SHOW PROCESSLIST' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->Id );
		$this->assertSame( 'root', $rows[0]->User );
		$this->assertSame( 'localhost', $rows[0]->Host );
		$this->assertSame( 'wptests', $rows[0]->db );
		$this->assertSame( 'Query', $rows[0]->Command );
		$this->assertSame( '0', $rows[0]->Time );
		$this->assertSame( '', $rows[0]->State );
		$this->assertSame( 'SHOW PROCESSLIST', $rows[0]->Info );
		$this->assertSame( array( 'Id', 'User', 'Host', 'db', 'Command', 'Time', 'State', 'Info' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );
		$full = $driver->query( 'SHOW FULL PROCESSLIST', PDO::FETCH_ASSOC );

		$this->assertSame( 'information_schema', $full[0]['db'] );
		$this->assertSame( 'SHOW FULL PROCESSLIST', $full[0]['Info'] );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$found_rows = $driver->query( 'SELECT FOUND_ROWS()' );
		$this->assertSame( '1', $found_rows[0]->{'FOUND_ROWS()'} );
	}

	/**
	 * Tests unsupported SHOW PROCESSLIST clauses fail before backend execution.
	 */
	public function test_unsupported_show_processlist_clauses_fail_closed(): void {
		$queries = array(
			"SHOW PROCESSLIST WHERE Command = 'Query'",
			'SHOW PROCESSLIST LIMIT 1',
			'SHOW GLOBAL PROCESSLIST',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW PROCESSLIST statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW PROCESSLIST statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
		}
	}

	/**
	 * Tests SHOW CHARACTER SET returns MySQL-shaped static character set rows.
	 */
	public function test_show_character_set_returns_mysql_shaped_rows(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( 'SHOW CHARACTER SET' );

		$this->assertEquals(
			array(
				(object) array(
					'Charset'           => 'binary',
					'Description'       => 'Binary pseudo charset',
					'Default collation' => 'binary',
					'Maxlen'            => '1',
				),
				(object) array(
					'Charset'           => 'utf8',
					'Description'       => 'UTF-8 Unicode',
					'Default collation' => 'utf8_general_ci',
					'Maxlen'            => '3',
				),
				(object) array(
					'Charset'           => 'utf8mb4',
					'Description'       => 'UTF-8 Unicode',
					'Default collation' => 'utf8mb4_0900_ai_ci',
					'Maxlen'            => '4',
				),
			),
			$rows
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame(
			array( 'Charset', 'Description', 'Default collation', 'Maxlen' ),
			array_column( $driver->get_last_column_meta(), 'name' )
		);

		$charset_rows = $driver->query( 'SHOW CHARSET' );
		$this->assertEquals( $rows, $charset_rows );

		$like_rows = $driver->query( "SHOW CHARACTER SET LIKE 'utf8%'" );
		$this->assertSame(
			array( 'utf8', 'utf8mb4' ),
			array_map(
				static function ( $row ): string {
					return $row->Charset;
				},
				$like_rows
			)
		);

		$where_rows = $driver->query( "SHOW CHARACTER SET WHERE Charset = 'utf8mb4'" );
		$this->assertSame( array( 'utf8mb4' ), array( $where_rows[0]->Charset ) );

		$collation_rows = $driver->query( "SHOW CHARACTER SET WHERE `Default collation` = 'binary'" );
		$this->assertSame( array( 'binary' ), array( $collation_rows[0]->Charset ) );

		$where_expression_rows = $driver->query( "SHOW CHARACTER SET WHERE Charset <> 'binary' AND Maxlen >= 4" );
		$this->assertSame( array( 'utf8mb4' ), array( $where_expression_rows[0]->Charset ) );

		$literal_left_rows = $driver->query( "SHOW CHARACTER SET WHERE 'Charset' = 'utf8mb4'" );
		$this->assertSame( array(), $literal_left_rows );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
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

		$where_expression_rows = $driver->query( "SHOW COLLATION WHERE Collation LIKE 'utf8%' AND Charset = 'utf8'" );
		$this->assertSame(
			array( 'utf8_bin', 'utf8_general_ci', 'utf8_unicode_ci' ),
			array_map(
				static function ( $row ): string {
					return $row->Collation;
				},
				$where_expression_rows
			)
		);

		$not_equal_rows = $driver->query( "SHOW COLLATION WHERE Collation <> 'binary' AND Charset = 'utf8mb4'" );
		$this->assertSame(
			array( 'utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci' ),
			array_map(
				static function ( $row ): string {
					return $row->Collation;
				},
				$not_equal_rows
			)
		);

		$literal_left_rows = $driver->query( "SHOW COLLATION WHERE 'Collation' = 'utf8_bin'" );
		$this->assertSame( array(), $literal_left_rows );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
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

		$where_like_rows = $driver->query( "SHOW DATABASES WHERE Database LIKE 'info%'" );
		$this->assertEquals( array( (object) array( 'Database' => 'information_schema' ) ), $where_like_rows );

		$where_or_rows = $driver->query( "SHOW DATABASES WHERE Database = 'wptests' OR Database = 'information_schema'" );
		$this->assertEquals( $databases, $where_or_rows );

		$this->assertSame( array(), $driver->query( "SHOW DATABASES WHERE 'Database' = 'information_schema'" ) );
		$this->assertSame( array(), $driver->query( 'SHOW DATABASES WHERE "Database" = \'information_schema\'' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

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

		$this->assertSame( array(), $tables );
		$this->assertSame( 'Tables_in_information_schema', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$full_tables = $driver->query( 'SHOW FULL TABLES' );

		$this->assertSame( array(), $full_tables );
		$this->assertSame( array( 'Tables_in_information_schema', 'Table_type' ), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$qualified_tables = $driver->query( 'SHOW TABLES FROM information_schema' );

		$this->assertSame( array(), $qualified_tables );
		$this->assertSame( 'Tables_in_information_schema', $driver->get_last_column_meta()[0]['name'] );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$table_status = $driver->query( 'SHOW TABLE STATUS' );

		$this->assertSame( array(), $table_status );
		$this->assertSame( $this->get_show_table_status_column_names(), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$qualified_table_status = $driver->query( 'SHOW TABLE STATUS FROM information_schema' );

		$this->assertSame( array(), $qualified_table_status );
		$this->assertSame( $this->get_show_table_status_column_names(), array_column( $driver->get_last_column_meta(), 'name' ) );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

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
	 * Tests USE information_schema routes supported direct table reads.
	 */
	public function test_use_statement_information_schema_table_reads_route_supported_relations(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$columns = $driver->query(
			"SELECT t.table_name AS table_name, c.column_name AS column_name
			FROM tables AS t, columns AS c
			WHERE c.table_schema = t.table_schema
				AND c.table_name = t.table_name
				AND t.table_name = 'wptests_options'
			ORDER BY c.ordinal_position"
		);

		$this->assertSame(
			array(
				array( 'wptests_options', 'option_id' ),
				array( 'wptests_options', 'option_name' ),
				array( 'wptests_options', 'option_value' ),
				array( 'wptests_options', 'autoload' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->table_name, $row->column_name );
				},
				$columns
			)
		);

		$sql = implode( "\n", array_column( $driver->get_last_postgresql_queries(), 'sql' ) );
		$this->assertStringContainsString( 'AS "t"', $sql );
		$this->assertStringContainsString( 'AS "c"', $sql );
		$this->assertStringContainsString( '"c"."TABLE_SCHEMA" = "t"."TABLE_SCHEMA"', $sql );

		$checks = $driver->query(
			"SELECT tc.constraint_name AS constraint_name, cc.check_clause AS check_clause
			FROM table_constraints AS tc
			JOIN check_constraints AS cc
				ON cc.constraint_schema = tc.constraint_schema
				AND cc.constraint_name = tc.constraint_name
			WHERE tc.table_name = 'wptests_posts'"
		);

		$this->assertEquals(
			array(
				(object) array(
					'constraint_name' => 'wptests_posts_status_chk',
					'check_clause'    => 'post_status IS NOT NULL',
				),
			),
			$checks
		);
	}

	/**
	 * Tests unsupported USE information_schema table reads fail closed.
	 */
	public function test_use_statement_information_schema_unsupported_table_reads_fail_closed(): void {
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
	 * Tests USE information_schema routes table metadata handlers.
	 */
	public function test_use_statement_information_schema_table_metadata_handlers_route_supported_relations(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$columns = $driver->query( "SHOW COLUMNS FROM tables WHERE Field = 'TABLE_NAME'" );

		$this->assertEquals(
			array(
				(object) array(
					'Field'   => 'TABLE_NAME',
					'Type'    => 'varchar(512)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
			),
			$columns
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$full_fields = $driver->query( "SHOW FULL FIELDS FROM columns WHERE Field = 'COLUMN_NAME'" );

		$this->assertCount( 1, $full_fields );
		$this->assertSame( 'COLUMN_NAME', $full_fields[0]->Field );
		$this->assertSame( 'varchar(512)', $full_fields[0]->Type );
		$this->assertNull( $full_fields[0]->Collation );
		$this->assertSame( 'select', $full_fields[0]->Privileges );

		$index_driver = $this->create_show_index_driver();

		$this->assertSame( 0, $index_driver->query( 'USE information_schema' ) );

		$indexes = $index_driver->query( 'SHOW INDEX FROM tables' );

		$this->assertSame( array(), $indexes );
		$this->assertSame(
			array(
				'Table',
				'Non_unique',
				'Key_name',
				'Seq_in_index',
				'Column_name',
				'Collation',
				'Cardinality',
				'Sub_part',
				'Packed',
				'Null',
				'Index_type',
				'Comment',
				'Index_comment',
				'Visible',
				'Expression',
			),
			array_column( $index_driver->get_last_column_meta(), 'name' )
		);
		$this->assertSame( array(), $index_driver->get_last_postgresql_queries() );

		$show_create_driver = $this->create_driver();

		$this->assertSame( 0, $show_create_driver->query( 'USE information_schema' ) );

		$show_create = $show_create_driver->query( 'SHOW CREATE TABLE tables' );

		$this->assertCount( 1, $show_create );
		$this->assertSame( 'tables', $show_create[0]->Table );
		$this->assertStringStartsWith( "CREATE TEMPORARY TABLE `tables` (\n", $show_create[0]->{'Create Table'} );
		$this->assertStringContainsString( '  `TABLE_NAME` varchar(512) DEFAULT NULL', $show_create[0]->{'Create Table'} );
		$this->assertSame( array(), $show_create_driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests SHOW COLUMNS/INDEX database clauses override qualified table prefixes.
	 */
	public function test_information_schema_show_metadata_database_clause_overrides_qualified_table_prefix(): void {
		$columns_driver = $this->create_driver();
		$this->install_information_schema_fixture( $columns_driver );

		$columns = $columns_driver->query( 'SHOW COLUMNS FROM information_schema.wptests_options FROM wptests' );

		$this->assertCount( 4, $columns );
		$this->assertSame( 'option_id', $columns[0]->Field );
		$this->assertSame( array( 'public', 'wptests_options' ), $columns_driver->get_last_postgresql_queries()[0]['params'] );

		$index_driver = $this->create_show_index_driver();
		$indexes      = $index_driver->query( 'SHOW INDEXES FROM other_db.wptests_options FROM wptests' );

		$this->assertCount( 3, $indexes );
		$this->assertSame( 'PRIMARY', $indexes[0]->Key_name );
		$this->assertSame( array( 'public', 'wptests_options' ), $index_driver->get_last_postgresql_queries()[0]['params'] );
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
	 * Tests writes after USE information_schema fail before backend execution.
	 */
	public function test_use_statement_information_schema_writes_fail_closed(): void {
		$queries = array(
			'INSERT INTO tables (table_name) VALUES (\'t\')',
			'REPLACE INTO tables (table_name) VALUES (\'t\')',
			'UPDATE tables SET table_name = \'new_t\' WHERE table_name = \'t\'',
			'DELETE FROM tables WHERE table_name = \'t\'',
			'TRUNCATE tables',
			'CREATE TABLE new_table (id INT)',
			'ALTER TABLE tables ADD COLUMN new_column INT',
			'DROP TABLE tables',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_driver();
			$driver->query( 'CREATE TABLE tables (id INTEGER)' );

			$this->assertSame( 0, $driver->query( 'USE information_schema' ), $query );

			try {
				$driver->query( $query );
				$this->fail( 'Expected information_schema write to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported information_schema query.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
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
	 * Tests supported MySQL table administration modifiers are accepted as compatibility no-ops.
	 */
	public function test_table_administration_accepts_supported_mysql_option_clauses(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_existing (id INTEGER)' );

		$cases = array(
			'ANALYZE LOCAL TABLE administration_existing'                           => 'analyze',
			'ANALYZE NO_WRITE_TO_BINLOG TABLE administration_existing'              => 'analyze',
			'CHECK TABLE administration_existing FOR UPGRADE'                       => 'check',
			'CHECK TABLE administration_existing QUICK FAST MEDIUM EXTENDED CHANGED' => 'check',
			'OPTIMIZE LOCAL TABLE administration_existing'                          => 'optimize',
			'OPTIMIZE NO_WRITE_TO_BINLOG TABLE administration_existing'             => 'optimize',
			'REPAIR LOCAL TABLE administration_existing QUICK EXTENDED USE_FRM'      => 'repair',
			'REPAIR NO_WRITE_TO_BINLOG TABLE administration_existing USE_FRM'        => 'repair',
		);

		foreach ( $cases as $query => $operation ) {
			$rows = $driver->query( $query );

			$this->assertEquals(
				array(
					(object) array(
						'Table'    => 'wptests.administration_existing',
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
	}

	/**
	 * Tests unsupported table administration clauses fail before reaching the backend.
	 */
	public function test_table_administration_unsupported_clauses_fail_closed(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE administration_existing (id INTEGER)' );

		try {
			$driver->query( 'CHECK TABLE administration_existing UNKNOWN_OPTION' );
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

		$sql = $this->get_logged_postgresql_sql_containing( $driver->get_last_postgresql_queries(), 'AS "table"' );
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

		$sql = $this->get_logged_postgresql_sql_containing( $driver->get_last_postgresql_queries(), 'AS "table"' );
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
	 * Tests direct information_schema.TABLES SELECTs return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_tables_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$count = $driver->query( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'wptests'" );

		$this->assertSame( '3', $count[0]->{'COUNT(*)'} );
		$this->assertSame( 'COUNT(*)', $driver->get_last_column_meta()[0]['name'] );

		$current_schema_tables = $driver->query(
			"SELECT table_name FROM information_schema.tables
			WHERE table_schema = DATABASE()
				AND table_name = 'wptests_options'"
		);

		$this->assertCount( 1, $current_schema_tables );
		$this->assertSame( 'wptests_options', $current_schema_tables[0]->TABLE_NAME );

		$tables = $driver->query(
			"SELECT table_name AS name, ENGINE AS engine, CAST(data_length / 1024 / 1024 AS UNSIGNED) AS data
			FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_NAME = 'wptests_options'
			ORDER BY name ASC"
		);

		$this->assertCount( 1, $tables );
		$this->assertSame( 'wptests_options', $tables[0]->name );
		$this->assertSame( 'InnoDB', $tables[0]->engine );
		$this->assertSame( '0', $tables[0]->data );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$current_database_tables = $driver->query( "SELECT ENGINE FROM tables WHERE TABLE_NAME = 'wptests_options'" );

		$this->assertCount( 1, $current_database_tables );
		$this->assertSame( 'InnoDB', $current_database_tables[0]->ENGINE );
		$this->assertNotEmpty(
			array_filter(
				$driver->get_last_postgresql_queries(),
				static function ( array $query ): bool {
					return false !== strpos( $query['sql'], 'AS "tables"' );
				}
			)
		);
	}

	/**
	 * Tests direct information_schema schema predicates accept DATABASE() and SCHEMA().
	 */
	public function test_direct_information_schema_current_database_function_predicates_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$cases = array(
			'SELECT schema_name AS name FROM information_schema.schemata WHERE schema_name = DATABASE()' => 'wptests',
			"SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wptests_options'" => 'wptests_options',
			"SELECT column_name AS name FROM information_schema.columns AS c WHERE SCHEMA() = c.table_schema AND c.table_name = 'wptests_options' AND c.column_name = 'option_name'" => 'option_name',
			"SELECT index_name AS name FROM information_schema.statistics WHERE index_schema = SCHEMA() AND table_name = 'wptests_options' AND index_name = 'option_name'" => 'option_name',
			"SELECT constraint_name AS name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'wptests_options' AND constraint_name = 'PRIMARY'" => 'PRIMARY',
			"SELECT referenced_table_schema AS name FROM information_schema.key_column_usage WHERE referenced_table_schema = SCHEMA() AND table_name = 'wptests_posts'" => 'wptests',
			"SELECT unique_constraint_schema AS name FROM information_schema.referential_constraints WHERE unique_constraint_schema = DATABASE() AND table_name = 'wptests_posts'" => 'wptests',
			"SELECT constraint_schema AS name FROM information_schema.check_constraints WHERE constraint_schema = SCHEMA() AND constraint_name = 'wptests_posts_status_chk'" => 'wptests',
		);

		foreach ( $cases as $query => $expected_name ) {
			$rows = $driver->query( $query );

			$this->assertCount( 1, $rows, $query );
			$this->assertSame( $expected_name, $rows[0]->name, $query );

			$sql = implode( "\n", array_column( $driver->get_last_postgresql_queries(), 'sql' ) );
			$this->assertSame( 0, preg_match( '/\b(?:DATABASE|SCHEMA)\s*\(/i', $sql ), $query );
			$this->assertStringContainsString( "'wptests'", $sql, $query );
		}

		try {
			$driver->query( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE('wptests')" );
			$this->fail( 'Expected unsupported information_schema query.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported information_schema query.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
	}

	/**
	 * Tests direct information_schema.SCHEMATA SELECTs return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_schemata_selects_return_mysql_shape(): void {
		$driver = $this->create_driver( 'wp_test_new' );

		$schemata = $driver->query( 'SELECT schema_name FROM information_schema.schemata ORDER BY schema_name' );

		$this->assertEquals(
			array(
				(object) array( 'SCHEMA_NAME' => 'information_schema' ),
				(object) array( 'SCHEMA_NAME' => 'wp_test_new' ),
			),
			$schemata
		);

		$aliased = $driver->query( 'SELECT s.schema_name FROM information_schema.schemata AS s WHERE s.schema_name = \'wp_test_new\'' );

		$this->assertEquals( array( (object) array( 'SCHEMA_NAME' => 'wp_test_new' ) ), $aliased );

		$star = $driver->query( 'SELECT * FROM information_schema.schemata WHERE schema_name = \'information_schema\'' );

		$this->assertCount( 1, $star );
		$this->assertSame( 'def', $star[0]->CATALOG_NAME );
		$this->assertSame( 'information_schema', $star[0]->SCHEMA_NAME );
		$this->assertSame( 'utf8mb4', $star[0]->DEFAULT_CHARACTER_SET_NAME );
		$this->assertSame( 'utf8mb4_unicode_ci', $star[0]->DEFAULT_COLLATION_NAME );
		$this->assertNull( $star[0]->SQL_PATH );
		$this->assertSame( 'NO', $star[0]->DEFAULT_ENCRYPTION );

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$current_database_schemata = $driver->query( 'SELECT schema_name FROM schemata WHERE schema_name = \'wp_test_new\'' );

		$this->assertEquals( array( (object) array( 'SCHEMA_NAME' => 'wp_test_new' ) ), $current_database_schemata );
	}

	/**
	 * Tests direct information_schema charset and collation SELECTs return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_character_sets_and_collations_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();

		$character_sets = $driver->query( 'SELECT * FROM INFORMATION_SCHEMA.CHARACTER_SETS ORDER BY CHARACTER_SET_NAME' );

		$this->assertEquals(
			array(
				(object) array(
					'CHARACTER_SET_NAME'   => 'binary',
					'DEFAULT_COLLATE_NAME' => 'binary',
					'DESCRIPTION'          => 'Binary pseudo charset',
					'MAXLEN'               => '1',
				),
				(object) array(
					'CHARACTER_SET_NAME'   => 'utf8',
					'DEFAULT_COLLATE_NAME' => 'utf8_general_ci',
					'DESCRIPTION'          => 'UTF-8 Unicode',
					'MAXLEN'               => '3',
				),
				(object) array(
					'CHARACTER_SET_NAME'   => 'utf8mb4',
					'DEFAULT_COLLATE_NAME' => 'utf8mb4_0900_ai_ci',
					'DESCRIPTION'          => 'UTF-8 Unicode',
					'MAXLEN'               => '4',
				),
			),
			$character_sets
		);

		$collations = $driver->query( 'SELECT * FROM INFORMATION_SCHEMA.COLLATIONS ORDER BY COLLATION_NAME' );

		$this->assertEquals(
			array(
				(object) array(
					'COLLATION_NAME'     => 'binary',
					'CHARACTER_SET_NAME' => 'binary',
					'ID'                 => '63',
					'IS_DEFAULT'         => 'Yes',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '1',
					'PAD_ATTRIBUTE'      => 'NO PAD',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8_bin',
					'CHARACTER_SET_NAME' => 'utf8',
					'ID'                 => '83',
					'IS_DEFAULT'         => '',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '1',
					'PAD_ATTRIBUTE'      => 'PAD SPACE',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8_general_ci',
					'CHARACTER_SET_NAME' => 'utf8',
					'ID'                 => '33',
					'IS_DEFAULT'         => 'Yes',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '1',
					'PAD_ATTRIBUTE'      => 'PAD SPACE',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8_unicode_ci',
					'CHARACTER_SET_NAME' => 'utf8',
					'ID'                 => '192',
					'IS_DEFAULT'         => '',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '8',
					'PAD_ATTRIBUTE'      => 'PAD SPACE',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8mb4_0900_ai_ci',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'ID'                 => '255',
					'IS_DEFAULT'         => 'Yes',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '0',
					'PAD_ATTRIBUTE'      => 'NO PAD',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8mb4_bin',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'ID'                 => '46',
					'IS_DEFAULT'         => '',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '1',
					'PAD_ATTRIBUTE'      => 'PAD SPACE',
				),
				(object) array(
					'COLLATION_NAME'     => 'utf8mb4_unicode_ci',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'ID'                 => '224',
					'IS_DEFAULT'         => '',
					'IS_COMPILED'        => 'Yes',
					'SORTLEN'            => '8',
					'PAD_ATTRIBUTE'      => 'PAD SPACE',
				),
			),
			$collations
		);

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$defaults = $driver->query(
			"SELECT cs.character_set_name, c.collation_name
			FROM character_sets AS cs
			JOIN collations AS c ON c.character_set_name = cs.character_set_name
			WHERE c.is_default = 'Yes'
			ORDER BY c.collation_name"
		);

		$this->assertEquals(
			array(
				(object) array(
					'CHARACTER_SET_NAME' => 'binary',
					'COLLATION_NAME'     => 'binary',
				),
				(object) array(
					'CHARACTER_SET_NAME' => 'utf8',
					'COLLATION_NAME'     => 'utf8_general_ci',
				),
				(object) array(
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'COLLATION_NAME'     => 'utf8mb4_0900_ai_ci',
				),
			),
			$defaults
		);
	}

	/**
	 * Tests direct information_schema aliases and star projections return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_alias_star_and_count_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$tables = $driver->query( "SELECT t.* FROM information_schema.tables AS t WHERE t.table_name = 'wptests_options'" );

		$this->assertCount( 1, $tables );
		$this->assertSame( 'def', $tables[0]->TABLE_CATALOG );
		$this->assertSame( 'wptests', $tables[0]->TABLE_SCHEMA );
		$this->assertSame( 'wptests_options', $tables[0]->TABLE_NAME );
		$this->assertSame( 'BASE TABLE', $tables[0]->TABLE_TYPE );
		$this->assertSame( 'InnoDB', $tables[0]->ENGINE );
		$this->assertSame( 'utf8mb4_unicode_ci', $tables[0]->TABLE_COLLATION );

		$count = $driver->query( "SELECT COUNT(*) FROM information_schema.columns AS c WHERE c.table_name = 'wptests_options'" );

		$this->assertSame( '4', $count[0]->{'COUNT(*)'} );
		$this->assertSame( 'COUNT(*)', $driver->get_last_column_meta()[0]['name'] );
		$this->assertStringContainsString( 'AS "c"', $driver->get_last_postgresql_queries()[0]['sql'] );
	}

	/**
	 * Tests direct information_schema joins rewrite every emulated relation source.
	 */
	public function test_direct_information_schema_tables_columns_join_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$columns = $driver->query(
			"SELECT t.table_name AS table_name, c.column_name AS column_name, c.ordinal_position AS ordinal_position
			FROM information_schema.tables AS t
			JOIN information_schema.columns AS c
				ON c.table_schema = t.table_schema
				AND c.table_name = t.table_name
			WHERE t.table_schema = 'wptests'
				AND t.table_name = 'wptests_options'
			ORDER BY c.ordinal_position"
		);

		$this->assertSame(
			array(
				array( 'wptests_options', 'option_id', '1' ),
				array( 'wptests_options', 'option_name', '2' ),
				array( 'wptests_options', 'option_value', '3' ),
				array( 'wptests_options', 'autoload', '4' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->table_name, $row->column_name, $row->ordinal_position );
				},
				$columns
			)
		);

		$sql = implode( "\n", array_column( $driver->get_last_postgresql_queries(), 'sql' ) );
		$this->assertStringContainsString( 'AS "t"', $sql );
		$this->assertStringContainsString( 'AS "c"', $sql );
		$this->assertStringContainsString( '"c"."TABLE_SCHEMA" = "t"."TABLE_SCHEMA"', $sql );
	}

	/**
	 * Tests direct information_schema JOIN ... USING rewrites merged MySQL columns.
	 */
	public function test_direct_information_schema_join_using_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$columns = $driver->query(
			"SELECT table_name AS table_name, c.column_name AS column_name, c.ordinal_position AS ordinal_position
			FROM information_schema.tables AS t
			JOIN information_schema.columns AS c USING (table_schema, table_name)
			WHERE table_name = 'wptests_options'
			ORDER BY c.ordinal_position"
		);

		$this->assertSame(
			array(
				array( 'wptests_options', 'option_id', '1' ),
				array( 'wptests_options', 'option_name', '2' ),
				array( 'wptests_options', 'option_value', '3' ),
				array( 'wptests_options', 'autoload', '4' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->table_name, $row->column_name, $row->ordinal_position );
				},
				$columns
			)
		);

		$sql = implode( "\n", array_column( $driver->get_last_postgresql_queries(), 'sql' ) );
		$this->assertStringContainsString( 'USING ("TABLE_SCHEMA", "TABLE_NAME")', $sql );
		$this->assertStringContainsString( 'WHERE "TABLE_NAME" = \'wptests_options\'', $sql );

		$key_usage = $driver->query(
			"SELECT constraint_name AS constraint_name, kcu.column_name AS column_name
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.key_column_usage AS kcu
				USING (constraint_schema, constraint_name, table_schema, table_name)
			WHERE table_name = 'wptests_options'
				AND constraint_name = 'PRIMARY'"
		);

		$this->assertSame( 'PRIMARY', $key_usage[0]->constraint_name );
		$this->assertSame( 'option_id', $key_usage[0]->column_name );

		$sql = implode( "\n", array_column( $driver->get_last_postgresql_queries(), 'sql' ) );
		$this->assertStringContainsString( 'USING ("CONSTRAINT_SCHEMA", "CONSTRAINT_NAME", "TABLE_SCHEMA", "TABLE_NAME")', $sql );
		$this->assertStringContainsString( 'WHERE "TABLE_NAME" = \'wptests_options\'', $sql );
		$this->assertStringContainsString( '"CONSTRAINT_NAME" = \'PRIMARY\'', $sql );
	}

	/**
	 * Tests direct information_schema derived SELECT sources return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_derived_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$aliased = $driver->query(
			"SELECT d.table_name AS table_name
			FROM (
				SELECT table_name
				FROM information_schema.tables
				WHERE table_schema = 'wptests'
			) AS d
			WHERE d.table_name = 'wptests_options'"
		);

		$this->assertCount( 1, $aliased );
		$this->assertSame( 'wptests_options', $aliased[0]->table_name );

		$sql = $this->get_logged_postgresql_sql_containing( $driver->get_last_postgresql_queries(), 'AS "d"' );
		$this->assertStringContainsString( 'AS "d"', $sql );
		$this->assertStringContainsString( '"d"."TABLE_NAME" = \'wptests_options\'', $sql );

		$unaliased = $driver->query(
			"SELECT table_name
			FROM (
				SELECT table_name
				FROM information_schema.tables
				WHERE table_schema = 'wptests'
			)
			WHERE table_name = 'wptests_options'"
		);

		$this->assertCount( 1, $unaliased );
		$this->assertSame( 'wptests_options', $unaliased[0]->TABLE_NAME );
		$this->assertStringContainsString(
			'AS "derived"',
			$this->get_logged_postgresql_sql_containing( $driver->get_last_postgresql_queries(), 'AS "derived"' )
		);
	}

	/**
	 * Tests INSERT ... SELECT can source supported direct information_schema relations.
	 */
	public function test_insert_select_from_information_schema_routes_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			'CREATE TABLE wptests_information_schema_insert (
				id INTEGER NOT NULL,
				value TEXT NOT NULL
			)'
		);

		$insert = "INSERT INTO wptests_information_schema_insert (id, value)
			SELECT 1, table_name
			FROM information_schema.tables
			WHERE table_schema = 'wptests'
				AND table_name = 'wptests_options'";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$sql     = $this->get_logged_postgresql_sql_containing( $queries, 'INSERT INTO wptests_information_schema_insert' );
		$this->assertStringContainsString( 'INSERT INTO wptests_information_schema_insert', $sql );
		$this->assertStringContainsString( '"TABLE_NAME"', $sql );
		$this->assertStringContainsString( 'AS "tables"', $sql );

		$rows = $driver->query( 'SELECT id, value FROM wptests_information_schema_insert' );
		$this->assertSame( '1', $rows[0]->id );
		$this->assertSame( 'wptests_options', $rows[0]->value );
	}

	/**
	 * Tests INSERT ... SELECT routes supported multi-source information_schema joins.
	 */
	public function test_insert_select_from_information_schema_join_routes_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			'CREATE TABLE wptests_information_schema_join_insert (
				id INTEGER NOT NULL,
				value TEXT NOT NULL
			)'
		);

		$insert = "INSERT INTO wptests_information_schema_join_insert (id, value)
			SELECT 2, it.table_name
			FROM information_schema.schemata AS s
			JOIN information_schema.tables AS it ON s.schema_name = it.table_schema
			WHERE s.schema_name = 'wptests'
				AND it.table_name = 'wptests_options'";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$sql     = $this->get_logged_postgresql_sql_containing( $queries, 'INSERT INTO wptests_information_schema_join_insert' );
		$this->assertStringContainsString( 'AS "s"', $sql );
		$this->assertStringContainsString( 'AS "it"', $sql );
		$this->assertStringContainsString( '"s"."SCHEMA_NAME" = "it"."TABLE_SCHEMA"', $sql );

		$rows = $driver->query( 'SELECT id, value FROM wptests_information_schema_join_insert' );
		$this->assertSame( '2', $rows[0]->id );
		$this->assertSame( 'wptests_options', $rows[0]->value );
	}

	/**
	 * Tests INSERT ... SELECT routes supported information_schema JOIN ... USING.
	 */
	public function test_insert_select_from_information_schema_join_using_routes_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$driver->get_connection()->get_pdo()->exec(
			'CREATE TABLE wptests_information_schema_using_insert (
				id INTEGER NOT NULL,
				value TEXT NOT NULL
			)'
		);

		$insert = "INSERT INTO wptests_information_schema_using_insert (id, value)
			SELECT c.ordinal_position, table_name
			FROM information_schema.tables AS t
			JOIN information_schema.columns AS c USING (table_schema, table_name)
			WHERE table_name = 'wptests_options'
				AND c.column_name = 'option_name'";

		$this->assertSame( 1, $driver->query( $insert ) );

		$queries = $driver->get_last_postgresql_queries();
		$sql     = $this->get_logged_postgresql_sql_containing( $queries, 'INSERT INTO wptests_information_schema_using_insert' );
		$this->assertStringContainsString( 'USING ("TABLE_SCHEMA", "TABLE_NAME")', $sql );
		$this->assertStringContainsString( '"c"."ORDINAL_POSITION"', $sql );
		$this->assertStringContainsString( '"TABLE_NAME"', $sql );

		$rows = $driver->query( 'SELECT id, value FROM wptests_information_schema_using_insert' );
		$this->assertSame( '2', $rows[0]->id );
		$this->assertSame( 'wptests_options', $rows[0]->value );
	}

	/**
	 * Tests INSERT ... SELECT routes supported nested information_schema SELECTs.
	 */
	public function test_insert_select_from_nested_information_schema_routes_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->get_connection()->get_pdo()->exec(
			'CREATE TABLE wptests_information_schema_nested_insert (
				id INTEGER NOT NULL,
				value TEXT NOT NULL
			)'
		);

		$this->assertSame(
			1,
			$driver->query(
				'INSERT INTO wptests_information_schema_nested_insert (id, value)
				SELECT 3, table_name
				FROM (
					SELECT table_name
					FROM information_schema.tables
					WHERE table_name = "wptests_options"
				)'
			)
		);

		$queries = $driver->get_last_postgresql_queries();
		$sql     = $this->get_logged_postgresql_sql_containing( $queries, 'INSERT INTO wptests_information_schema_nested_insert' );
		$this->assertStringContainsString( 'AS "derived"', $sql );
		$this->assertStringContainsString( '"TABLE_NAME"', $sql );

		$this->assertSame(
			1,
			$driver->query(
				'INSERT INTO wptests_information_schema_nested_insert (id, value)
				SELECT 4, table_name
				FROM information_schema.tables
				WHERE table_name IN (
					SELECT table_name FROM information_schema.tables
				)
				AND table_name = "wptests_options"'
			)
		);

		$rows = $driver->query( 'SELECT id, value FROM wptests_information_schema_nested_insert ORDER BY id' );
		$this->assertSame(
			array(
				array( '3', 'wptests_options' ),
				array( '4', 'wptests_options' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->value );
				},
				$rows
			)
		);
	}

	/**
	 * Tests direct information_schema SELECTs can join one application table source.
	 */
	public function test_direct_information_schema_mixed_application_table_join_returns_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query(
			'CREATE TABLE wptests_schema_names (
				id INTEGER NOT NULL,
				db_name TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_schema_names (
				id int(11) NOT NULL,
				db_name varchar(191) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_schema_names (id, db_name)
			VALUES (1, 'other'), (2, 'wptests')"
		);

		$result = $driver->query(
			"SELECT sub.id, sub.table_schema, sub.table_name, sub.column_name
			FROM (
				SELECT *
				FROM information_schema.columns AS c
				JOIN wptests_schema_names AS app
					ON app.db_name = CONCAT(COALESCE(c.table_schema, 'default'), '')
				JOIN information_schema.schemata AS s
					ON s.schema_name = c.table_schema
				WHERE c.table_name = 'wptests_schema_names'
			) AS sub
			ORDER BY ordinal_position"
		);

		$this->assertSame(
			array(
				array( '2', 'wptests', 'wptests_schema_names', 'id' ),
				array( '2', 'wptests', 'wptests_schema_names', 'db_name' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->TABLE_SCHEMA, $row->TABLE_NAME, $row->COLUMN_NAME );
				},
				$result
			)
		);

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertStringContainsString( '"wptests_schema_names" AS "app"', $sql );
		$this->assertStringContainsString( '"app"."db_name" = CONCAT', $sql );
		$this->assertStringContainsString( 'COALESCE ( "c"."TABLE_SCHEMA" , \'default\')', $sql );
	}

	/**
	 * Tests direct information_schema joins accept main database-qualified application tables.
	 */
	public function test_direct_information_schema_mixed_join_accepts_main_database_qualified_application_table(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query(
			'CREATE TABLE wptests_schema_names (
				id INTEGER NOT NULL,
				db_name TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_schema_names (
				id int(11) NOT NULL,
				db_name varchar(191) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_schema_names (id, db_name)
			VALUES (1, 'other'), (2, 'wptests')"
		);

		$this->assertSame( 0, $driver->query( 'USE information_schema' ) );

		$result = $driver->query(
			"SELECT app.id, c.table_schema, c.table_name, c.column_name
			FROM columns AS c
			JOIN wptests.wptests_schema_names AS app
				ON app.db_name = c.table_schema
			WHERE c.table_name = 'wptests_schema_names'
			ORDER BY c.ordinal_position"
		);

		$this->assertSame(
			array(
				array( '2', 'wptests', 'wptests_schema_names', 'id' ),
				array( '2', 'wptests', 'wptests_schema_names', 'db_name' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->id, $row->TABLE_SCHEMA, $row->TABLE_NAME, $row->COLUMN_NAME );
				},
				$result
			)
		);

		$sql = $this->get_last_single_postgresql_sql( $driver );
		$this->assertStringContainsString( '"wptests_schema_names" AS "app"', $sql );
		$this->assertStringContainsString( '"app"."db_name" = "c"."TABLE_SCHEMA"', $sql );
		$this->assertStringNotContainsString( 'wptests.wptests_schema_names', $sql );
	}

	/**
	 * Tests unsupported mixed information_schema joins fail before backend execution.
	 */
	public function test_direct_information_schema_mixed_join_shape_fails_closed(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		foreach (
			array(
				'SELECT t.table_name
				FROM information_schema.tables AS t
				WHERE t.table_name IN (
					SELECT option_name FROM wptests_options
				)',
				'SELECT table_name
				FROM information_schema.tables AS t
				JOIN information_schema.columns AS c
					ON c.table_schema = t.table_schema
					AND c.table_name = t.table_name',
				'SELECT table_name
				FROM information_schema.tables AS t
				JOIN information_schema.columns AS c USING (engine)',
				'SELECT table_name
				FROM information_schema.tables AS t
				JOIN information_schema.columns AS c USING (column_name)',
				'SELECT table_name
				FROM information_schema.tables AS t
				JOIN information_schema.columns AS c USING (table_schema + table_name)',
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported information_schema query.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported information_schema query.', $e->getMessage(), $query );
			}

			$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
		}
	}

	/**
	 * Tests direct information_schema column/index/constraint SELECTs use MySQL metadata.
	 */
	public function test_direct_information_schema_columns_statistics_and_key_constraints_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );
		$this->install_direct_information_schema_options_metadata( $driver );

		$columns = $driver->query(
			"SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY, EXTRA
			FROM information_schema.columns
			WHERE table_name = 'wptests_options'
			ORDER BY ordinal_position"
		);

		$this->assertSame(
			array(
				array( 'option_id', 'bigint', 'bigint(20) unsigned', 'PRI', 'auto_increment' ),
				array( 'option_name', 'varchar', 'varchar(191)', 'UNI', '' ),
				array( 'option_value', 'longtext', 'longtext', '', '' ),
				array( 'autoload', 'varchar', 'varchar(20)', 'MUL', '' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->COLUMN_NAME, $row->DATA_TYPE, $row->COLUMN_TYPE, $row->COLUMN_KEY, $row->EXTRA );
				},
				$columns
			)
		);

		$statistics = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
			FROM information_schema.statistics
			WHERE table_name = 'wptests_options'
			ORDER BY INDEX_NAME, SEQ_IN_INDEX"
		);

		$statistics_by_name = array();
		foreach ( $statistics as $row ) {
			$statistics_by_name[ $row->INDEX_NAME ] = array( $row->COLUMN_NAME, $row->NON_UNIQUE, $row->SEQ_IN_INDEX );
		}
		ksort( $statistics_by_name );

		$this->assertSame(
			array(
				'PRIMARY'     => array( 'option_id', '0', '1' ),
				'autoload'    => array( 'autoload', '1', '1' ),
				'option_name' => array( 'option_name', '0', '1' ),
			),
			$statistics_by_name
		);

		$current_schema_statistics = $driver->query(
			"SELECT INDEX_NAME
			FROM information_schema.statistics
			WHERE table_schema = SCHEMA()
				AND table_name = 'wptests_options'
				AND index_name = 'option_name'"
		);

		$this->assertCount( 1, $current_schema_statistics );
		$this->assertSame( 'option_name', $current_schema_statistics[0]->INDEX_NAME );

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
			FROM information_schema.table_constraints
			WHERE table_name = 'wptests_options'
			ORDER BY CONSTRAINT_NAME"
		);

		$this->assertSame(
			array(
				array( 'PRIMARY', 'PRIMARY KEY' ),
				array( 'option_name', 'UNIQUE' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->CONSTRAINT_NAME, $row->CONSTRAINT_TYPE );
				},
				$constraints
			)
		);

		$key_usage = $driver->query(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
			FROM information_schema.key_column_usage
			WHERE table_name = 'wptests_options'
			ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION"
		);

		$this->assertSame(
			array(
				array( 'PRIMARY', 'option_id', '1' ),
				array( 'option_name', 'option_name', '1' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->CONSTRAINT_NAME, $row->COLUMN_NAME, $row->ORDINAL_POSITION );
				},
				$key_usage
			)
		);
	}

	/**
	 * Tests direct FK and CHECK information_schema SELECTs return MySQL-shaped rows.
	 */
	public function test_direct_information_schema_foreign_and_check_constraints_selects_return_mysql_shape(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
			FROM information_schema.table_constraints
			WHERE table_name = 'wptests_posts'
			ORDER BY CONSTRAINT_NAME"
		);

		$this->assertSame(
			array(
				array( 'wptests_posts_author_fk', 'FOREIGN KEY' ),
				array( 'wptests_posts_status_chk', 'CHECK' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->CONSTRAINT_NAME, $row->CONSTRAINT_TYPE );
				},
				$constraints
			)
		);

		$key_usage = $driver->query(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_name = 'wptests_posts'
			ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION"
		);

		$this->assertSame(
			array(
				array( 'wptests_posts_author_fk', 'post_author', 'wptests_options', 'option_id' ),
			),
			array_map(
				static function ( $row ): array {
					return array( $row->CONSTRAINT_NAME, $row->COLUMN_NAME, $row->REFERENCED_TABLE_NAME, $row->REFERENCED_COLUMN_NAME );
				},
				$key_usage
			)
		);

		$referential = $driver->query(
			"SELECT CONSTRAINT_NAME, DELETE_RULE, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE table_name = 'wptests_posts'"
		);

		$this->assertSame( 'wptests_posts_author_fk', $referential[0]->CONSTRAINT_NAME );
		$this->assertSame( 'CASCADE', $referential[0]->DELETE_RULE );
		$this->assertSame( 'wptests_options', $referential[0]->REFERENCED_TABLE_NAME );

		$checks = $driver->query(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_name = 'wptests_posts_status_chk'"
		);

		$this->assertSame( 'wptests_posts_status_chk', $checks[0]->CONSTRAINT_NAME );
		$this->assertSame( 'post_status IS NOT NULL', $checks[0]->CHECK_CLAUSE );
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
	 * Tests SHOW INDEX reports MySQL FULLTEXT and SPATIAL metadata rows.
	 */
	public function test_show_index_reports_fulltext_and_spatial_metadata_rows(): void {
		$driver = $this->create_show_index_driver();
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO show_index_fixture
				(table_schema, table_name, sort_position, non_unique, key_name, seq_in_index, column_name, collation, cardinality, sub_part, packed, nullable, index_type, comment, index_comment, visible, expression)
			VALUES
				('public', 'wptests_search_geo', 1, '1', 'shape_spatial', '1', 'shape', 'A', '0', '32', NULL, '', 'SPATIAL', '', '', 'YES', NULL),
				('public', 'wptests_search_geo', 2, '1', 'body_fulltext', '1', 'body', NULL, '0', NULL, NULL, '', 'FULLTEXT', '', '', 'YES', NULL)"
		);

		$indexes = $driver->query( 'SHOW INDEX FROM wptests_search_geo' );

		$this->assertCount( 2, $indexes );
		$this->assertSame( 'shape_spatial', $indexes[0]->Key_name );
		$this->assertSame( 'SPATIAL', $indexes[0]->Index_type );
		$this->assertSame( 'A', $indexes[0]->Collation );
		$this->assertSame( '32', $indexes[0]->Sub_part );
		$this->assertSame( 'body_fulltext', $indexes[1]->Key_name );
		$this->assertSame( 'FULLTEXT', $indexes[1]->Index_type );
		$this->assertNull( $indexes[1]->Collation );
		$this->assertNull( $indexes[1]->Sub_part );
		$this->assertStringContainsString(
			'CASE WHEN im.index_type = \'FULLTEXT\' THEN NULL ELSE COALESCE(im.collation, \'A\') END AS "Collation"',
			$driver->get_last_postgresql_queries()[0]['sql']
		);
	}

	/**
	 * Tests SHOW INDEX WHERE filters match FULLTEXT/SPATIAL metadata output columns.
	 */
	public function test_show_index_where_filters_fulltext_and_spatial_metadata_rows(): void {
		$driver = $this->create_show_index_driver();
		$driver->get_connection()->get_pdo()->exec(
			"INSERT INTO show_index_fixture
				(table_schema, table_name, sort_position, non_unique, key_name, seq_in_index, column_name, collation, cardinality, sub_part, packed, nullable, index_type, comment, index_comment, visible, expression)
			VALUES
				('public', 'wptests_search_geo', 1, '1', 'shape_spatial', '1', 'shape', 'A', '0', '32', NULL, '', 'SPATIAL', '', '', 'YES', NULL),
				('public', 'wptests_search_geo', 2, '1', 'body_fulltext', '1', 'body', NULL, '0', NULL, NULL, '', 'FULLTEXT', '', '', 'YES', NULL)"
		);

		$fulltext = $driver->query( "SHOW INDEX FROM wptests_search_geo WHERE Index_type = 'FULLTEXT'" );

		$this->assertCount( 1, $fulltext );
		$this->assertSame( 'body_fulltext', $fulltext[0]->Key_name );
		$this->assertSame( 'FULLTEXT', $fulltext[0]->Index_type );
		$this->assertNull( $fulltext[0]->Sub_part );
		$this->assertSame( array( 'public', 'wptests_search_geo', 'FULLTEXT' ), $driver->get_last_postgresql_queries()[0]['params'] );

		$spatial = $driver->query( 'SHOW INDEX FROM wptests_search_geo WHERE Sub_part = 32' );

		$this->assertCount( 1, $spatial );
		$this->assertSame( 'shape_spatial', $spatial[0]->Key_name );
		$this->assertSame( 'SPATIAL', $spatial[0]->Index_type );
		$this->assertSame( '32', $spatial[0]->Sub_part );
		$this->assertSame( array( 'public', 'wptests_search_geo', '32' ), $driver->get_last_postgresql_queries()[0]['params'] );
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
	 * Tests SHOW INDEX WHERE exact filters support additional output columns.
	 */
	public function test_show_index_where_exact_filters_additional_output_columns(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( "SHOW INDEX FROM wptests_options WHERE Column_name = 'option_name'" );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'option_name', $indexes[0]->Key_name );
		$this->assertSame( 'option_name', $indexes[0]->Column_name );
		$this->assertSame( '0', $indexes[0]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Column_name" = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW INDEX', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_options', 'option_name' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW KEYS WHERE exact filters support additional output columns.
	 */
	public function test_show_keys_where_exact_filters_additional_output_columns(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( 'SHOW KEYS FROM wptests_options WHERE Non_unique = 0' );

		$this->assertCount( 2, $indexes );
		$this->assertSame( 'PRIMARY', $indexes[0]->Key_name );
		$this->assertSame( 'option_name', $indexes[1]->Key_name );
		$this->assertSame( array( '0', '0' ), array( $indexes[0]->Non_unique, $indexes[1]->Non_unique ) );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Non_unique" = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW KEYS', strtoupper( $queries[0]['sql'] ) );
		$this->assertSame( array( 'public', 'wptests_options', '0' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW INDEX-family WHERE LIKE filters support allowed output columns.
	 */
	public function test_show_index_family_where_like_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_index_like (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			)"
		);

		$indexes = $driver->query( "SHOW INDEX FROM wptests_index_like WHERE Key_name LIKE 'auto%'" );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'autoload', $indexes[0]->Key_name );
		$this->assertSame( 'autoload', $indexes[0]->Column_name );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Key_name" LIKE ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW INDEX', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_index_like', 'auto%' ), $queries[0]['params'] );

		$indexes = $driver->query( "SHOW KEYS FROM wptests_index_like WHERE Column_name LIKE 'option_%'" );

		$this->assertSame( array( 'PRIMARY', 'option_name' ), array( $indexes[0]->Key_name, $indexes[1]->Key_name ) );
		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Column_name" LIKE ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW KEYS', strtoupper( $queries[0]['sql'] ) );
		$this->assertSame( array( 'public', 'wptests_index_like', 'option_%' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW INDEX-family WHERE filters support simple AND combinations.
	 */
	public function test_show_index_family_where_and_filters_catalog_rows(): void {
		$driver = $this->create_driver();
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_index_and (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			)"
		);

		$indexes = $driver->query( "SHOW INDEX FROM wptests_index_and WHERE Key_name LIKE 'auto%' AND Non_unique = 1" );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'autoload', $indexes[0]->Key_name );
		$this->assertSame( 'autoload', $indexes[0]->Column_name );
		$this->assertSame( '1', $indexes[0]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'WHERE "Key_name" LIKE ? ESCAPE \'\\\' AND "Non_unique" = ?', $queries[0]['sql'] );
		$this->assertStringNotContainsString( 'SHOW INDEX', $queries[0]['sql'] );
		$this->assertSame( array( 'public', 'wptests_index_and', 'auto%', '1' ), $queries[0]['params'] );
	}

	/**
	 * Tests SHOW INDEX-family WHERE expression filters catalog rows after fetching.
	 */
	public function test_show_index_family_where_expression_filters_catalog_rows(): void {
		$driver = $this->create_show_index_driver();

		$indexes = $driver->query( 'SHOW KEYS FROM wptests_options WHERE Non_unique > 0' );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'autoload', $indexes[0]->Key_name );
		$this->assertSame( '1', $indexes[0]->Non_unique );

		$queries = $driver->get_last_postgresql_queries();
		$this->assertCount( 1, $queries );
		$this->assertSame( array( 'public', 'wptests_options' ), $queries[0]['params'] );
		$this->assertStringNotContainsString( 'WHERE "Non_unique"', $queries[0]['sql'] );

		$indexes = $driver->query( "SHOW INDEX FROM wptests_options WHERE Key_name = 'PRIMARY' OR Non_unique = 1" );

		$this->assertSame( array( 'PRIMARY', 'autoload' ), array( $indexes[0]->Key_name, $indexes[1]->Key_name ) );
		$this->assertSame( array( '0', '1' ), array( $indexes[0]->Non_unique, $indexes[1]->Non_unique ) );
		$this->assertSame( array( 'public', 'wptests_options' ), $driver->get_last_postgresql_queries()[0]['params'] );
	}

	/**
	 * Tests SHOW INDEX-family statements accept current database qualification forms.
	 */
	public function test_show_index_accepts_current_database_qualification_forms(): void {
		$cases = array(
			'SHOW INDEX FROM wptests.wptests_options' => array( 'public', 'wptests_options' ),
			'SHOW KEYS IN wptests_options' => array( 'public', 'wptests_options' ),
			'SHOW INDEXES FROM wptests_options FROM wptests' => array( 'public', 'wptests_options' ),
			"SHOW KEYS FROM wptests_options IN `wptests` WHERE Key_name = 'autoload'" => array( 'public', 'wptests_options', 'autoload' ),
		);

		foreach ( $cases as $query => $params ) {
			$driver = $this->create_show_index_driver();

			$indexes = $driver->query( $query );

			$this->assertNotCount( 0, $indexes, $query );
			$this->assertSame( 'wptests_options', $indexes[0]->Table, $query );

			$queries = $driver->get_last_postgresql_queries();
			$this->assertCount( 1, $queries, $query );
			$this->assertStringContainsString( 'pg_catalog.pg_index', $queries[0]['sql'], $query );
			$this->assertSame( $params, $queries[0]['params'], $query );
		}
	}

	/**
	 * Tests unsupported SHOW INDEX-family clauses fail before reaching the backend.
	 */
	public function test_show_index_family_unsupported_syntax_does_not_reach_backend(): void {
		$queries = array(
			'SHOW KEYS FROM wptests_options WHERE Key_name LIKE autoload',
			'SHOW KEYS FROM wptests_options LIMIT 1',
		);

		foreach ( $queries as $query ) {
			$driver = $this->create_show_index_driver();

			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW INDEX statement to throw.' );
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
	 * Tests CREATE TABLE ... AS SELECT is translated and stores MySQL-facing metadata.
	 */
	public function test_create_table_as_select_translates_and_stores_metadata(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query( 'CREATE TABLE ctas_source (`id` INTEGER, `name` TEXT)' );
		$driver->query( "INSERT INTO ctas_source (`id`, `name`) VALUES (1, 'one'), (2, 'two')" );

		$this->assertGreaterThanOrEqual(
			0,
			$driver->query( 'CREATE TABLE ctas_copy AS SELECT `id`, `name` FROM `ctas_source` WHERE `id` > 1' )
		);
		$this->assertSame(
			'CREATE TABLE "ctas_copy" AS SELECT "id", "name" FROM "ctas_source" WHERE "id" > 1',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT * FROM ctas_copy' );
		$this->assertEquals(
			array(
				(object) array(
					'id'   => '2',
					'name' => 'two',
				),
			),
			$rows
		);

		$columns = $driver->query( 'SHOW COLUMNS FROM ctas_copy' );
		$this->assertSame( 'id', $columns[0]->Field );
		$this->assertSame( 'int', $columns[0]->Type );
		$this->assertSame( 'name', $columns[1]->Field );
		$this->assertSame( 'text', $columns[1]->Type );

		$this->assertSame(
			array(
				array(
					'column_name'        => 'id',
					'column_type'        => 'int',
					'character_set_name' => null,
					'collation_name'     => null,
					'is_nullable'        => 'YES',
					'column_default'     => null,
					'extra'              => '',
				),
				array(
					'column_name'        => 'name',
					'column_type'        => 'text',
					'character_set_name' => 'utf8mb4',
					'collation_name'     => 'utf8mb4_unicode_ci',
					'is_nullable'        => 'YES',
					'column_default'     => null,
					'extra'              => '',
				),
			),
			$this->get_mysql_column_metadata_rows( $driver, 'ctas_copy' )
		);
	}

	/**
	 * Tests CREATE TEMPORARY TABLE ... SELECT is translated and stores temporary metadata.
	 */
	public function test_create_temporary_table_select_translates_and_stores_metadata(): void {
		$driver = $this->create_driver();
		$this->install_information_schema_fixture( $driver );

		$driver->query( 'CREATE TABLE ctas_temp_source (`id` INTEGER, `name` TEXT)' );
		$driver->query( "INSERT INTO ctas_temp_source (`id`, `name`) VALUES (1, 'one'), (2, 'two')" );

		$this->assertGreaterThanOrEqual(
			0,
			$driver->query( 'CREATE TEMPORARY TABLE ctas_temp SELECT `name` FROM `ctas_temp_source` WHERE `id` = 2' )
		);
		$this->assertSame(
			'CREATE TEMPORARY TABLE "ctas_temp" AS SELECT "name" FROM "ctas_temp_source" WHERE "id" = 2',
			$this->get_last_single_postgresql_sql( $driver )
		);

		$rows = $driver->query( 'SELECT * FROM ctas_temp' );
		$this->assertEquals( array( (object) array( 'name' => 'two' ) ), $rows );

		$columns = $driver->query( 'SHOW COLUMNS FROM ctas_temp' );
		$this->assertSame( 'name', $columns[0]->Field );
		$this->assertSame( 'text', $columns[0]->Type );

		$this->assertSame(
			array(
				array(
					'column_name'        => 'name',
					'column_type'        => 'text',
					'character_set_name' => 'utf8mb4',
					'collation_name'     => 'utf8mb4_unicode_ci',
					'is_nullable'        => 'YES',
					'column_default'     => null,
					'extra'              => '',
				),
			),
			$this->get_mysql_column_metadata_rows( $driver, 'ctas_temp', 'temp' )
		);
	}

	/**
	 * Tests unsupported CREATE TABLE ... SELECT variants fail without backend execution.
	 */
	public function test_create_table_as_select_with_column_definitions_fails_closed(): void {
		$driver = $this->create_driver();

		try {
			$driver->query( 'CREATE TABLE ctas_with_definitions (`id` INTEGER) AS SELECT 1 AS `id`' );
			$this->fail( 'Expected unsupported CREATE TABLE statement to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( 'Unsupported CREATE TABLE statement.', $e->getMessage() );
			$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		}
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
	 * Tests the PostgreSQL driver defaults to the same MySQL SQL modes as SQLite.
	 */
	public function test_default_sql_mode_matches_sqlite_backend_defaults(): void {
		$driver = $this->create_driver();

		$expected = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';

		$this->assertSame( $expected, $driver->get_sql_mode() );

		$rows = $driver->query( 'SELECT @@sql_mode' );
		$this->assertSame( $expected, $rows[0]->{'@@sql_mode'} );

		$rows = $driver->query( "SHOW VARIABLES LIKE 'sql_mode'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( $expected, $rows[0]->Value );
	}

	/**
	 * Tests supported SQL mode SET syntaxes normalize and report emulated state.
	 */
	public function test_sql_mode_set_syntaxes_update_emulated_state(): void {
		$driver = $this->create_driver();

		$this->assertSame( 0, $driver->query( 'SET sql_mode = "ERROR_FOR_DIVISION_BY_ZERO"' ) );
		$this->assertSame( 'ERROR_FOR_DIVISION_BY_ZERO', $driver->get_sql_mode() );

		$this->assertSame( 0, $driver->query( "SET @@sql_mode = 'NO_ENGINE_SUBSTITUTION'" ) );
		$this->assertSame( 'NO_ENGINE_SUBSTITUTION', $driver->get_sql_mode() );

		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'NO_ZERO_DATE'" ) );
		$this->assertSame( 'NO_ZERO_DATE', $driver->get_sql_mode() );

		$this->assertSame( 0, $driver->query( "SET @@SESSION.sql_mode = 'NO_ZERO_IN_DATE'" ) );
		$rows = $driver->query( 'SELECT @@SESSION.sql_mode' );
		$this->assertSame( 'NO_ZERO_IN_DATE', $rows[0]->{'@@SESSION.sql_mode'} );

		$this->assertSame( 0, $driver->query( 'SET sql_mode = DEFAULT' ) );
		$this->assertSame(
			'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
			$driver->get_sql_mode()
		);

		$this->assertSame( 0, $driver->query( 'SET sql_mode = 0' ) );
		$this->assertSame( '', $driver->get_sql_mode() );
	}

	/**
	 * Tests SQL mode user-variable save/restore flows.
	 */
	public function test_sql_mode_can_be_saved_and_restored_through_user_variables(): void {
		$driver       = $this->create_driver();
		$initial_mode = $driver->get_sql_mode();

		$this->assertSame( 0, $driver->query( 'SET @old_sql_mode = @@SESSION.sql_mode' ) );
		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'ANSI_QUOTES'" ) );
		$this->assertSame( 'ANSI_QUOTES', $driver->get_sql_mode() );

		$this->assertSame( 0, $driver->query( 'SET SESSION sql_mode = @old_sql_mode' ) );
		$this->assertSame( $initial_mode, $driver->get_sql_mode() );
	}

	/**
	 * Tests ANSI_QUOTES affects PostgreSQL query translation.
	 */
	public function test_ansi_quotes_sql_mode_treats_double_quoted_text_as_identifiers(): void {
		$driver = $this->create_driver();

		$driver->query( 'CREATE TABLE wptests_posts ("ID" INTEGER PRIMARY KEY, post_title TEXT NOT NULL)' );
		$driver->query( 'INSERT INTO wptests_posts ("ID", post_title) VALUES (1, \'Hello\')' );

		$literal = $driver->query( 'SELECT "post_title" AS value' );
		$this->assertSame( 'post_title', $literal[0]->value );

		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'ANSI_QUOTES'" ) );
		$rows = $driver->query( 'SELECT "ID", "post_title" FROM "wptests_posts" WHERE "ID" = 1' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]->ID );
		$this->assertSame( 'Hello', $rows[0]->post_title );
		$this->assertSame(
			'SELECT "ID", "post_title" FROM "wptests_posts" WHERE "ID" = 1',
			$this->get_last_single_postgresql_sql( $driver )
		);
	}

	/**
	 * Tests NO_BACKSLASH_ESCAPES changes PostgreSQL string literal translation.
	 */
	public function test_no_backslash_escapes_sql_mode_changes_postgresql_string_literals(): void {
		$driver    = $this->create_driver();
		$backslash = chr( 92 );
		$query     = "SELECT '{$backslash}n' AS value";

		$driver->set_sql_mode( '' );
		$rows = $driver->query( $query );
		$this->assertSame( "\n", $rows[0]->value );

		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'" ) );
		$rows = $driver->query( $query );
		$this->assertSame( $backslash . 'n', $rows[0]->value );
	}

	/**
	 * Tests PIPES_AS_CONCAT switches || from logical OR to concatenation.
	 */
	public function test_pipes_as_concat_sql_mode_changes_postgresql_double_pipe_translation(): void {
		$driver = $this->create_driver();

		$driver->set_sql_mode( '' );
		$rows = $driver->query( 'SELECT 0 || 1 AS value' );
		$this->assertSame( '1', (string) $rows[0]->value );
		$this->assertSame( 'SELECT 0 OR 1 AS value', $this->get_last_single_postgresql_sql( $driver ) );

		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'PIPES_AS_CONCAT'" ) );
		$rows = $driver->query( "SELECT 'a' || 'b' AS value" );
		$this->assertSame( 'ab', $rows[0]->value );
		$this->assertSame( "SELECT 'a' || 'b' AS value", $this->get_last_single_postgresql_sql( $driver ) );
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

		$variables = array();
		foreach ( $rows as $row ) {
			$variables[ $row->Variable_name ] = $row->Value;
		}

		$this->assertGreaterThan( 9, count( $variables ) );
		$this->assertSame( 'utf8', $variables['character_set_client'] );
		$this->assertSame( 'utf8', $variables['character_set_connection'] );
		$this->assertSame( 'utf8', $variables['character_set_results'] );
		$this->assertSame( 'utf8', $variables['character_set_database'] );
		$this->assertSame( 'utf8', $variables['character_set_server'] );
		$this->assertSame( 'utf8_general_ci', $variables['collation_connection'] );
		$this->assertSame( 'utf8_general_ci', $variables['collation_database'] );
		$this->assertSame( 'utf8_general_ci', $variables['collation_server'] );
		$this->assertSame(
			'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
			$variables['sql_mode']
		);
		$this->assertSame( '1', $variables['autocommit'] );
		$this->assertSame( 'InnoDB', $variables['default_storage_engine'] );
		$this->assertSame( '1', $variables['foreign_key_checks'] );
		$this->assertSame( '67108864', $variables['max_allowed_packet'] );
		$this->assertSame( 'SYSTEM', $variables['time_zone'] );
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

		$where_like = $driver->query( "SHOW VARIABLES WHERE Variable_name LIKE 'character_set_%'" );
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
				$where_like
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$session_where = $driver->query( "SHOW SESSION VARIABLES WHERE Variable_name = 'collation_connection'" );
		$this->assertCount( 1, $session_where );
		$this->assertSame( 'collation_connection', $session_where[0]->Variable_name );
		$this->assertSame( 'utf8mb4_unicode_ci', $session_where[0]->Value );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$value_exact = $driver->query( "SHOW VARIABLES WHERE Value = 'utf8mb4'" );
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
				$value_exact
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$value_like = $driver->query( "SHOW VARIABLES WHERE Value LIKE 'utf8mb4_%'" );
		$this->assertSame(
			array(
				'default_collation_for_utf8mb4',
				'collation_connection',
				'collation_database',
				'collation_server',
			),
			array_map(
				static function ( $row ) {
					return $row->Variable_name;
				},
				$value_like
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$and_filter = $driver->query( "SHOW VARIABLES WHERE Variable_name LIKE 'character_set_%' AND Value = 'utf8mb4'" );
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
				$and_filter
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$value_not_equal = $driver->query( "SHOW VARIABLES WHERE Value <> 'utf8mb4'" );
		$this->assertNotSame( array(), $value_not_equal );
		foreach ( $value_not_equal as $row ) {
			$this->assertNotSame( 'utf8mb4', $row->Value );
		}
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );

		$or_filter = $driver->query( "SHOW VARIABLES WHERE Variable_name LIKE 'character_set_%' OR Value = 'utf8mb4'" );
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
				$or_filter
			)
		);
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
	}

	/**
	 * Tests unsupported SHOW VARIABLES WHERE clauses fail before backend execution.
	 */
	public function test_unsupported_show_variables_where_clause_does_not_reach_backend(): void {
		$driver = $this->create_driver();

		foreach (
			array(
				"SHOW VARIABLES WHERE Unknown = 'utf8mb4'",
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW VARIABLES statement to throw.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Unsupported SHOW VARIABLES statement.', $e->getMessage(), $query );
				$this->assertSame( array(), $driver->get_last_postgresql_queries(), $query );
			}
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

		$rows = $driver->query( 'SELECT @@SESSION.max_allowed_packet' );
		$this->assertSame( '67108864', $rows[0]->{'@@SESSION.max_allowed_packet'} );
		$rows = $driver->query( "SHOW VARIABLES WHERE Variable_name='max_allowed_packet'" );
		$this->assertSame( '67108864', $rows[0]->Value );

		$this->assertSame( 0, $driver->query( "SET SESSION time_zone = '+00:00'" ) );
		$rows = $driver->query( 'SELECT @@time_zone' );
		$this->assertSame( '+00:00', $rows[0]->{'@@time_zone'} );
		$rows = $driver->query( "SHOW VARIABLES WHERE Variable_name='time_zone'" );
		$this->assertSame( '+00:00', $rows[0]->Value );

		$this->assertSame( 0, $driver->query( 'SET GLOBAL foreign_key_checks = 0' ) );
		$rows = $driver->query( 'SELECT @@foreign_key_checks' );
		$this->assertSame( '0', $rows[0]->{'@@foreign_key_checks'} );
		$rows = $driver->query( "SHOW VARIABLES WHERE Variable_name='foreign_key_checks'" );
		$this->assertSame( '0', $rows[0]->Value );

		$this->assertSame( 0, $driver->query( "SET GLOBAL sql_mode = 'ANSI_QUOTES'" ) );
		$rows = $driver->query( 'SELECT @@GLOBAL.sql_mode' );
		$this->assertSame( 'ANSI_QUOTES', $rows[0]->{'@@GLOBAL.sql_mode'} );
		$rows = $driver->query( "SHOW VARIABLES WHERE Variable_name='sql_mode'" );
		$this->assertSame( 'ANSI_QUOTES', $rows[0]->Value );
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
	 * Install a DML coercion table with temporal/YEAR MySQL metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_strict_dml_values_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_strict_values (
				id INTEGER PRIMARY KEY,
				date_value TEXT,
				datetime_value TEXT,
				timestamp_value TEXT,
				year_value TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_strict_values (
				id int(11) NOT NULL,
				date_value date DEFAULT NULL,
				datetime_value datetime DEFAULT NULL,
				timestamp_value timestamp DEFAULT NULL,
				year_value year DEFAULT NULL,
				PRIMARY KEY (id)
			)'
		);
	}

	/**
	 * Install a DML coercion table with integer-family MySQL metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_strict_integer_values_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_strict_ints (
				id INTEGER PRIMARY KEY,
				int_value INTEGER,
				tiny_unsigned INTEGER,
				small_value INTEGER,
				int_unsigned TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_strict_ints (
				id int(11) NOT NULL,
				int_value int(11) DEFAULT NULL,
				tiny_unsigned tinyint(3) unsigned DEFAULT NULL,
				small_value smallint(6) DEFAULT NULL,
				int_unsigned int(10) unsigned DEFAULT NULL,
				PRIMARY KEY (id)
			)'
		);
	}

	/**
	 * Install a DML coercion table with bounded text MySQL metadata.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_strict_text_values_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_strict_texts (
				id INTEGER PRIMARY KEY,
				varchar_value TEXT,
				char_value TEXT,
				tinytext_value TEXT
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_strict_texts (
				id int(11) NOT NULL,
				varchar_value varchar(3) DEFAULT NULL,
				char_value char(3) DEFAULT NULL,
				tinytext_value tinytext,
				PRIMARY KEY (id)
			)'
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
		$driver->get_connection()->query(
			'CREATE UNIQUE INDEX prefix_ambiguous__slug ON prefix_ambiguous (SUBSTR(CAST(slug AS text), 1, 10))'
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
	 * Install an identity upsert table with a non-AUTO_INCREMENT unique key.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_identity_unique_upsert_table_with_mysql_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_identity_unique_upsert (
				id INTEGER PRIMARY KEY,
				slug TEXT NOT NULL UNIQUE,
				value TEXT NOT NULL
			)'
		);
		$driver->store_mysql_schema_metadata(
			'CREATE TABLE wptests_identity_unique_upsert (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
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
	 * Get the first logged PostgreSQL SQL statement containing a string.
	 *
	 * @param array[] $queries Logged PostgreSQL query records.
	 * @param string  $needle  SQL fragment to find.
	 * @return string Matching SQL statement.
	 */
	private function get_logged_postgresql_sql_containing( array $queries, string $needle ): string {
		foreach ( $queries as $query ) {
			if ( false !== strpos( $query['sql'], $needle ) ) {
				return $query['sql'];
			}
		}

		$this->fail( 'Expected PostgreSQL SQL containing: ' . $needle );
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
	 * Creates a PostgreSQL driver with SQLite shims for text runtime functions.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver_with_postgresql_text_runtime_functions(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection(
			array(
				'pdo' => $this->create_pdo_with_postgresql_text_runtime_functions(),
			)
		);
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Creates a PostgreSQL quote-translation driver with SQLite shims for text runtime functions.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver_with_postgresql_quote_translation_and_text_runtime_functions(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection_Pgsql_Quote_SQLite_Connection(
			array(
				'pdo' => $this->create_pdo_with_postgresql_text_runtime_functions(),
			)
		);
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Creates a SQLite PDO with shims for PostgreSQL text runtime functions.
	 *
	 * @return PDO
	 */
	private function create_pdo_with_postgresql_text_runtime_functions(): PDO {
		$pdo_class = class_exists( 'Pdo\Sqlite' ) ? 'Pdo\Sqlite' : PDO::class;
		$pdo       = new $pdo_class( 'sqlite::memory:' );

		$octet_length = static function ( $value ): ?int {
			return null === $value ? null : strlen( (string) $value );
		};
		$char_length  = static function ( $value ): ?int {
			if ( null === $value ) {
				return null;
			}

			$count = preg_match_all( '/./us', (string) $value );
			return false === $count ? strlen( (string) $value ) : $count;
		};
		$convert_to   = static function ( $value, $encoding ): ?string {
			if ( null === $value ) {
				return null;
			}

			return 'UTF8' === strtoupper( (string) $encoding ) ? (string) $value : null;
		};
		$strpos       = static function ( $value, $needle ): ?int {
			if ( null === $value || null === $needle ) {
				return null;
			}

			$position = strpos( (string) $value, (string) $needle );
			return false === $position ? 0 : $position + 1;
		};
		$translate    = static function ( $value, $from, $to ): ?string {
			if ( null === $value || null === $from || null === $to ) {
				return null;
			}

			$map         = array();
			$from        = (string) $from;
			$to          = (string) $to;
			$from_length = strlen( $from );
			$to_length   = strlen( $to );
			for ( $i = 0; $i < $from_length; $i++ ) {
				$map[ $from[ $i ] ] = $i < $to_length ? $to[ $i ] : '';
			}

			return strtr( (string) $value, $map );
		};

		if ( method_exists( $pdo, 'createFunction' ) ) {
			$pdo->createFunction( 'OCTET_LENGTH', $octet_length, 1 );
			$pdo->createFunction( 'CHAR_LENGTH', $char_length, 1 );
			$pdo->createFunction( 'CHARACTER_LENGTH', $char_length, 1 );
			$pdo->createFunction( 'CONVERT_TO', $convert_to, 2 );
			$pdo->createFunction( 'STRPOS', $strpos, 2 );
			$pdo->createFunction( 'TRANSLATE', $translate, 3 );
		} else {
			$pdo->sqliteCreateFunction( 'OCTET_LENGTH', $octet_length, 1 );
			$pdo->sqliteCreateFunction( 'CHAR_LENGTH', $char_length, 1 );
			$pdo->sqliteCreateFunction( 'CHARACTER_LENGTH', $char_length, 1 );
			$pdo->sqliteCreateFunction( 'CONVERT_TO', $convert_to, 2 );
			$pdo->sqliteCreateFunction( 'STRPOS', $strpos, 2 );
			$pdo->sqliteCreateFunction( 'TRANSLATE', $translate, 3 );
		}

		return $pdo;
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
		return $this->get_expected_date_arithmetic_with_interval_sql(
			$operator,
			$expression_sql,
			$this->get_expected_mysql_interval_sql( $value_sql, $unit )
		);
	}

	/**
	 * Get expected PostgreSQL SQL for MySQL DATE_ADD/DATE_SUB arithmetic with an interval SQL expression.
	 *
	 * @param string $operator       PostgreSQL interval operator.
	 * @param string $expression_sql PostgreSQL date/time expression SQL.
	 * @param string $interval_sql   PostgreSQL interval SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_date_arithmetic_with_interval_sql( string $operator, string $expression_sql, string $interval_sql ): string {
		return sprintf(
			'(%1$s %2$s %3$s)',
			$this->get_expected_zero_date_safe_timestamp_sql( $expression_sql ),
			$operator,
			$interval_sql
		);
	}

	/**
	 * Get expected PostgreSQL SQL for a MySQL-compatible interval expression.
	 *
	 * @param string $value_sql PostgreSQL interval value SQL.
	 * @param string $unit      Normalized interval unit.
	 * @return string PostgreSQL interval SQL.
	 */
	private function get_expected_mysql_interval_sql( string $value_sql, string $unit ): string {
		$interval_unit = '3 months' === $unit ? $unit : '1 ' . $unit;

		return sprintf(
			"(%1\$s * INTERVAL '%2\$s')",
			$this->get_expected_mysql_interval_value_sql( $value_sql, $unit ),
			$interval_unit
		);
	}

	/**
	 * Get expected PostgreSQL SQL for parsed MySQL composite interval components.
	 *
	 * @param array<int,array{0: string, 1: string}> $components Parsed interval component value/unit pairs.
	 * @return string PostgreSQL interval SQL.
	 */
	private function get_expected_mysql_composite_interval_sql( array $components ): string {
		$parts = array();
		foreach ( $components as $component ) {
			$parts[] = sprintf(
				"(CAST('%1\$s' AS double precision) * INTERVAL '1 %2\$s')",
				$component[0],
				$component[1]
			);
		}

		return '(' . implode( ' + ', $parts ) . ')';
	}

	/**
	 * Get expected PostgreSQL SQL for a MySQL-compatible interval value.
	 *
	 * @param string $value_sql PostgreSQL expression SQL.
	 * @return string PostgreSQL expression SQL.
	 */
	private function get_expected_mysql_interval_value_sql( string $value_sql, string $unit ): string {
		$value_cast_sql = 'second' === $unit
			? $this->get_expected_mysql_numeric_cast_sql( $value_sql )
			: $this->get_expected_mysql_integer_cast_sql( $value_sql );

		return sprintf( 'CAST(%s AS double precision)', $value_cast_sql );
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

			case 'QUARTER':
				return sprintf( 'CAST(FLOOR((CAST(SUBSTRING(%s FROM 6 FOR 2) AS integer) + 2) / 3.0) AS integer)', $expression_text_sql );

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
				'SELECT key_name, seq_in_index, column_name, non_unique, index_type, collation, sub_part, nullable
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
	 * Get stored MySQL foreign key metadata rows for a table.
	 *
	 * @param WP_PostgreSQL_Driver $driver     Driver under test.
	 * @param string               $table_name Table name.
	 * @param string               $schema     Metadata schema name.
	 * @return array Stored metadata rows.
	 */
	private function get_mysql_foreign_key_metadata_rows( WP_PostgreSQL_Driver $driver, string $table_name, string $schema = 'public' ): array {
		$stmt = $driver->get_connection()->query(
			sprintf(
				'SELECT constraint_name, seq_in_index, column_name, referenced_table_name, referenced_column_name, update_rule, delete_rule
				FROM %s
				WHERE table_schema = ? AND table_name = ?
				ORDER BY constraint_name, seq_in_index',
				$driver->get_connection()->quote_identifier( WP_PostgreSQL_Driver::MYSQL_FOREIGN_KEY_METADATA_TABLE )
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
	 * Install MySQL-facing metadata for direct information_schema SELECT tests.
	 *
	 * @param WP_PostgreSQL_Driver $driver Driver under test.
	 */
	private function install_direct_information_schema_options_metadata( WP_PostgreSQL_Driver $driver ): void {
		$driver->store_mysql_schema_metadata(
			"CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			)"
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
				numeric_precision INTEGER,
				numeric_scale INTEGER,
				datetime_precision INTEGER,
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
				column_name TEXT NOT NULL,
				ordinal_position INTEGER,
				position_in_unique_constraint INTEGER
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.referential_constraints (
				constraint_schema TEXT NOT NULL,
				constraint_name TEXT NOT NULL,
				unique_constraint_schema TEXT NOT NULL,
				unique_constraint_name TEXT NOT NULL,
				match_option TEXT NOT NULL,
				update_rule TEXT NOT NULL,
				delete_rule TEXT NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.check_constraints (
				constraint_schema TEXT NOT NULL,
				constraint_name TEXT NOT NULL,
				check_clause TEXT NOT NULL
			)'
		);
		$pdo->exec(
			'CREATE TABLE information_schema.constraint_column_usage (
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
				('public', 'wptests_options_option_name_key', 'public', 'wptests_options', 'UNIQUE'),
				('public', 'wptests_posts_author_fk', 'public', 'wptests_posts', 'FOREIGN KEY'),
				('public', 'wptests_posts_status_chk', 'public', 'wptests_posts', 'CHECK')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.key_column_usage
				(constraint_schema, constraint_name, table_schema, table_name, column_name, ordinal_position, position_in_unique_constraint)
			VALUES
				('public', 'wptests_options_pkey', 'public', 'wptests_options', 'option_id', 1, NULL),
				('public', 'wptests_options_option_name_key', 'public', 'wptests_options', 'option_name', 1, NULL),
				('public', 'wptests_posts_author_fk', 'public', 'wptests_posts', 'post_author', 1, 1)"
		);
		$pdo->exec(
			"INSERT INTO information_schema.referential_constraints
				(constraint_schema, constraint_name, unique_constraint_schema, unique_constraint_name, match_option, update_rule, delete_rule)
			VALUES
				('public', 'wptests_posts_author_fk', 'public', 'wptests_options_pkey', 'NONE', 'NO ACTION', 'CASCADE')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.constraint_column_usage
				(constraint_schema, constraint_name, table_schema, table_name, column_name)
			VALUES
				('public', 'wptests_options_pkey', 'public', 'wptests_options', 'option_id'),
				('public', 'wptests_posts_author_fk', 'public', 'wptests_options', 'option_id')"
		);
		$pdo->exec(
			"INSERT INTO information_schema.check_constraints
				(constraint_schema, constraint_name, check_clause)
			VALUES
				('public', 'wptests_posts_status_chk', 'post_status IS NOT NULL')"
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
