<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * @group duckdb
 */
class WP_DuckDB_Driver_Tests extends WP_DuckDB_TestCase {
	public function test_invalid_byte_string_literals_translate_without_token_value_type_error(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public function __construct() {}
		};
		$driver     = ( new ReflectionClass( WP_DuckDB_Driver::class ) )->newInstanceWithoutConstructor();

		foreach (
			array(
				'mysql_version'    => WP_DuckDB_Driver::DEFAULT_MYSQL_VERSION,
				'connection'       => $connection,
				'database'         => 'wp',
				'current_database' => 'wp',
			) as $property => $value
		) {
			$reflection_property = new ReflectionProperty( WP_DuckDB_Driver::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection_property->setAccessible( true );
			}
			$reflection_property->setValue( $driver, $value );
		}

		$grammar = new ReflectionProperty( WP_DuckDB_Driver::class, 'mysql_grammar' );
		if ( PHP_VERSION_ID < 80100 ) {
			$grammar->setAccessible( true );
		}
		$grammar->setValue( null, new WP_Parser_Grammar( require WP_DuckDB_Driver::MYSQL_GRAMMAR_PATH ) );

		$tokenize  = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$translate = new ReflectionMethod( WP_DuckDB_Driver::class, 'translate_tokens_to_duckdb_sql' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$translate->setAccessible( true );
		}

		$invalid_byte = "\xA1";
		$cases        = array(
			array(
				"SELECT CONVERT( LEFT( CONVERT( '{$invalid_byte}ord{$invalid_byte}ress' USING binary ), 100 ) USING big5 ) AS x_0",
				"SELECT LEFT('{$invalid_byte}ord{$invalid_byte}ress', 100) AS x_0",
			),
			array(
				"INSERT INTO wptests_posts (post_status) VALUES ('{$invalid_byte}')",
				"INSERT INTO wptests_posts(post_status) VALUES ('{$invalid_byte}')",
			),
			array(
				"DELETE FROM `wptests_posts` WHERE `post_status` = '{$invalid_byte}'",
				"DELETE FROM \"wptests_posts\" WHERE \"post_status\" = '{$invalid_byte}'",
			),
		);

		foreach ( $cases as $case ) {
			$tokens = $tokenize->invoke( $driver, $case[0] );
			$this->assertSame( bin2hex( $case[1] ), bin2hex( $translate->invoke( $driver, $tokens ) ) );
		}
	}

	public function test_charset_convert_sanitizer_select_returns_invalid_bytes_without_duckdb_runtime(): void {
		$driver = $this->new_byte_safe_duckdb_driver();
		$big5   = "a\xa6\x40b";
		$ujis   = "\xe8\x87\xaa\xe5\x8b\x95\xe4\xb8\x8b\xe6\x9b\xb8\xe3\x81\x8d";

		$cases = array(
			'cp1251_no_length'   => array(
				"SELECT CONVERT( CONVERT( '\xd8ord\xd0ress' USING cp1251 ) USING cp1251 ) AS x_0",
				"\xd8ord\xd0ress",
			),
			'koi8r_char_length'  => array(
				"SELECT CONVERT( LEFT( CONVERT( '" . str_repeat( "\xfd\xf2", 10 ) . "' USING koi8r ), 10 ) USING koi8r ) AS x_0",
				str_repeat( "\xfd\xf2", 5 ),
			),
			'hebrew_byte_length' => array(
				"SELECT CONVERT( LEFT( CONVERT( '" . str_repeat( "\xf9\xf7", 10 ) . "' USING binary ), 10 ) USING hebrew ) AS x_0",
				str_repeat( "\xf9\xf7", 5 ),
			),
			'tis620_char_length' => array(
				"SELECT CONVERT( LEFT( CONVERT( '" . str_repeat( "\xcc\xe3", 10 ) . "' USING tis620 ), 10 ) USING tis620 ) AS x_0",
				str_repeat( "\xcc\xe3", 5 ),
			),
			'big5_char_length'   => array(
				"SELECT CONVERT( LEFT( CONVERT( '" . str_repeat( $big5, 10 ) . "' USING big5 ), 10 ) USING big5 ) AS x_0",
				str_repeat( $big5, 3 ) . 'a',
			),
			'big5_byte_length'   => array(
				"SELECT CONVERT( LEFT( CONVERT( '" . str_repeat( $big5, 10 ) . "' USING binary ), 10 ) USING big5 ) AS x_0",
				str_repeat( $big5, 2 ) . 'a',
			),
			'ujis_utf8_length'   => array(
				"SELECT CONVERT( LEFT( CONVERT( '{$ujis}' USING ujis ), 4 ) USING utf8 ) AS x_0",
				"\xe8\x87\xaa\xe5\x8b\x95\xe4\xb8\x8b\xe6\x9b\xb8",
			),
		);

		foreach ( $cases as $label => $case ) {
			$row = $driver->query( $case[0] )->fetch( PDO::FETCH_ASSOC );
			$this->assertSame( bin2hex( $case[1] ), bin2hex( $row['x_0'] ), $label );
		}
	}

	public function test_charset_convert_sanitizer_select_handles_multiple_columns(): void {
		$driver = $this->new_byte_safe_duckdb_driver();
		$sql    = 'SELECT '
			. "CONVERT( CONVERT( '\xd8ord\xd0ress' USING cp1251 ) USING cp1251 ) AS x_0, "
			. "CONVERT( LEFT( CONVERT( '" . str_repeat( "\xf9\xf7", 10 ) . "' USING binary ), 10 ) USING hebrew ) AS x_1";

		$row = $driver->query( $sql )->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( bin2hex( "\xd8ord\xd0ress" ), bin2hex( $row['x_0'] ) );
		$this->assertSame( bin2hex( str_repeat( "\xf9\xf7", 5 ) ), bin2hex( $row['x_1'] ) );
	}

	public function test_charset_convert_sanitizer_select_does_not_intercept_generic_convert_using(): void {
		$driver       = $this->new_byte_safe_duckdb_driver();
		$invalid_byte = "\xA1";
		$queries      = array(
			'simple_convert'  => "SELECT CONVERT( 'Customer' USING utf8mb4 ) AS v",
			'mixed_select'    => "SELECT CONVERT( CONVERT( '{$invalid_byte}' USING cp1251 ) USING cp1251 ) AS x_0, 1 AS one",
			'from_expression' => "SELECT CONVERT( CONVERT( '{$invalid_byte}' USING cp1251 ) USING cp1251 ) AS x_0 FROM DUAL",
		);

		foreach ( $queries as $label => $sql ) {
			try {
				$driver->query( $sql );
				$this->fail( $label . ' should have used the generic DuckDB query path.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Unexpected DuckDB query:', $e->getMessage(), $label );
			}
		}
	}

	public function test_invalid_byte_write_display_sql_uses_byte_safe_token_value(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public function __construct() {}
		};
		$driver     = ( new ReflectionClass( WP_DuckDB_Driver::class ) )->newInstanceWithoutConstructor();

		foreach (
			array(
				'mysql_version'    => WP_DuckDB_Driver::DEFAULT_MYSQL_VERSION,
				'connection'       => $connection,
				'database'         => 'wp',
				'current_database' => 'wp',
			) as $property => $value
		) {
			$reflection_property = new ReflectionProperty( WP_DuckDB_Driver::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection_property->setAccessible( true );
			}
			$reflection_property->setValue( $driver, $value );
		}

		$grammar = new ReflectionProperty( WP_DuckDB_Driver::class, 'mysql_grammar' );
		if ( PHP_VERSION_ID < 80100 ) {
			$grammar->setAccessible( true );
		}
		$grammar->setValue( null, new WP_Parser_Grammar( require WP_DuckDB_Driver::MYSQL_GRAMMAR_PATH ) );

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$display  = new ReflectionMethod( WP_DuckDB_Driver::class, 'write_value_display_sql' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$display->setAccessible( true );
		}

		$invalid_byte = "\xA1";
		$tokens       = $tokenize->invoke( $driver, "INSERT INTO wptests_posts (post_status) VALUES ('{$invalid_byte}')" );
		$value_tokens = array_values(
			array_filter(
				$tokens,
				function ( WP_Parser_Token $token ): bool {
					return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id;
				}
			)
		);

		$this->assertSame( bin2hex( "'{$invalid_byte}'" ), bin2hex( $display->invoke( $driver, $value_tokens, "'{$invalid_byte}'" ) ) );
	}

	public function test_attachment_mime_distinct_no_order_rewrites_to_first_seen_order(): void {
		$driver = $this->new_byte_safe_duckdb_driver();

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$rewrite  = new ReflectionMethod( WP_DuckDB_Driver::class, 'attachment_mime_distinct_first_seen_order_sql' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$rewrite->setAccessible( true );
		}

		$tokens = $tokenize->invoke( $driver, "SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment'" );
		$this->assertSame(
			'SELECT "post_mime_type" FROM "wp_posts" WHERE "post_type" = \'attachment\' GROUP BY "post_mime_type" ORDER BY MIN(rowid)',
			$rewrite->invoke( $driver, $tokens )
		);
		$this->assertSame(
			'SELECT "post_mime_type" AS "mime" FROM "wp_posts" WHERE "post_type" = \'attachment\' GROUP BY "post_mime_type" ORDER BY MIN(rowid)',
			$rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT DISTINCT p.post_mime_type AS mime FROM wp_posts AS p WHERE p.post_type = 'attachment'" ) )
		);
		$this->assertSame(
			'SELECT "post_mime_type" FROM "wp_posts" WHERE "post_type" = \'attachment\' GROUP BY "post_mime_type" ORDER BY MIN(rowid)',
			$rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT DISTINCT wp_posts.post_mime_type FROM wp.wp_posts WHERE wp_posts.post_type = 'attachment'" ) )
		);
		$this->assertSame(
			'SELECT "post_mime_type" FROM "wp_posts" WHERE "post_type" = \'attachment\' AND "post_mime_type" != \'\' GROUP BY "post_mime_type" ORDER BY MIN(rowid)',
			$rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' AND post_mime_type != ''" ) )
		);
		$this->assertSame(
			'SELECT "post_mime_type" AS "mime" FROM "wp_posts" WHERE "post_type" = \'attachment\' AND "post_mime_type" != \'\' GROUP BY "post_mime_type" ORDER BY MIN(rowid)',
			$rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT DISTINCT p.post_mime_type AS mime FROM wp_posts AS p WHERE p.post_mime_type <> '' AND p.post_type = 'attachment'" ) )
		);

		foreach (
			array(
				"SELECT DISTINCT post_mime_type FROM media_items WHERE post_type = 'attachment'",
				"SELECT DISTINCT other_mime_type FROM wp_posts WHERE post_type = 'attachment'",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' ORDER BY post_mime_type",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' LIMIT 1",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' GROUP BY post_mime_type",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' AND post_status = 'inherit'",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' AND post_mime_type = ''",
				"SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'post'",
			) as $sql
		) {
			$this->assertNull( $rewrite->invoke( $driver, $tokenize->invoke( $driver, $sql ) ), $sql );
		}
	}

	public function test_posts_date_order_by_appends_id_tiebreaker(): void {
		$driver = $this->new_byte_safe_duckdb_driver();

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$rewrite  = new ReflectionMethod( WP_DuckDB_Driver::class, 'posts_date_order_by_tiebreak_rewrites' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$rewrite->setAccessible( true );
		}

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT ID FROM wptests_posts WHERE post_type = 'post' ORDER BY post_date DESC" ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'post_date DESC, "ID" DESC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, 'SELECT p.ID FROM wp.wp_posts AS p ORDER BY p.post_modified ASC' ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'p.post_modified ASC, "p"."ID" ASC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, 'SELECT ID FROM wp_posts ORDER BY post_modified DESC' ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'post_modified DESC, "ID" ASC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, 'SELECT ID FROM wp_posts ORDER BY post_date DESC, post_title ASC' ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'post_title ASC, "ID" DESC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, 'SELECT ID FROM wp_posts ORDER BY FIELD(ID, 4, 3) DESC, post_date DESC' ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'post_date DESC, "ID" DESC', $rewrites[0]['sql'] );

		foreach (
			array(
				'SELECT DISTINCT ID FROM wp_posts ORDER BY post_date DESC',
				'SELECT ID FROM wp_posts GROUP BY ID ORDER BY post_date DESC',
				'SELECT ID FROM wp_posts ORDER BY post_date DESC, ID DESC',
				'SELECT ID FROM media_items ORDER BY post_date DESC',
				'SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta pm ON pm.post_id = p.ID ORDER BY p.post_date DESC',
			) as $sql
		) {
			$this->assertSame( array(), $rewrite->invoke( $driver, $tokenize->invoke( $driver, $sql ) ), $sql );
		}
	}

	public function test_posts_page_hierarchy_order_by_appends_id_tiebreaker(): void {
		$driver = $this->new_byte_safe_duckdb_driver();

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$rewrite  = new ReflectionMethod( WP_DuckDB_Driver::class, 'posts_page_hierarchy_order_by_tiebreak_rewrites' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$rewrite->setAccessible( true );
		}

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT ID FROM wptests_posts WHERE post_type = 'page' ORDER BY menu_order ASC, post_title ASC" ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'post_title ASC, "ID" ASC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT p.ID FROM wp.wp_posts AS p WHERE p.post_type = 'page' ORDER BY p.menu_order, p.post_title" ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'p.post_title, "p"."ID" ASC', $rewrites[0]['sql'] );

		$rewrites = array_values( $rewrite->invoke( $driver, $tokenize->invoke( $driver, "SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE (wp_posts.post_type = 'page') AND (wp_posts.post_title LIKE '%Child%') ORDER BY wp_posts.menu_order ASC, wp_posts.post_title ASC LIMIT 0, 20" ) ) );
		$this->assertCount( 1, $rewrites );
		$this->assertSame( 'wp_posts.post_title ASC, "ID" ASC', $rewrites[0]['sql'] );

		foreach (
			array(
				"SELECT DISTINCT ID FROM wp_posts WHERE post_type = 'page' ORDER BY menu_order ASC, post_title ASC",
				"SELECT ID FROM wp_posts WHERE post_type = 'post' ORDER BY menu_order ASC, post_title ASC",
				'SELECT ID FROM wp_posts ORDER BY menu_order ASC, post_title ASC',
				"SELECT ID FROM wp_posts WHERE post_type = 'page' ORDER BY post_title ASC",
				"SELECT ID FROM wp_posts WHERE post_type = 'page' ORDER BY menu_order ASC, post_title DESC",
				"SELECT ID FROM wp_posts WHERE post_type = 'page' ORDER BY menu_order ASC, post_title ASC, ID ASC",
				'SELECT ID FROM wp_posts GROUP BY ID ORDER BY menu_order ASC, post_title ASC',
				"SELECT ID FROM media_items WHERE post_type = 'page' ORDER BY menu_order ASC, post_title ASC",
				"SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta pm ON pm.post_id = p.ID WHERE p.post_type = 'page' ORDER BY p.menu_order ASC, p.post_title ASC",
			) as $sql
		) {
			$this->assertSame( array(), $rewrite->invoke( $driver, $tokenize->invoke( $driver, $sql ) ), $sql );
		}
	}

	public function test_runtime_counters_emit_native_context_and_shape_when_enabled(): void {
		$previous_env        = getenv( 'WP_DUCKDB_RUNTIME_COUNTERS' );
		$previous_log_errors = ini_get( 'log_errors' );
		$previous_error_log  = ini_get( 'error_log' );
		$log_file            = tempnam( sys_get_temp_dir(), 'duckdb-runtime-counters-' );

		$this->reset_duckdb_runtime_counters_for_test();

		try {
			putenv( 'WP_DUCKDB_RUNTIME_COUNTERS=1' );
			ini_set( 'log_errors', '1' );
			ini_set( 'error_log', $log_file );

			$connection = new class() extends WP_DuckDB_Connection {
				public $queries = array();

				public function __construct() {}

				public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
					$this->queries[] = $sql;
					return new WP_DuckDB_Result_Statement( array( 'value' ), array( array( 'value' => 1 ) ), 1 );
				}
			};
			$driver     = ( new ReflectionClass( WP_DuckDB_Driver::class ) )->newInstanceWithoutConstructor();

			foreach (
				array(
					'connection'          => $connection,
					'last_duckdb_queries' => array(),
				) as $property => $value
			) {
				$reflection_property = new ReflectionProperty( WP_DuckDB_Driver::class, $property );
				if ( PHP_VERSION_ID < 80100 ) {
					$reflection_property->setAccessible( true );
				}
				$reflection_property->setValue( $driver, $value );
			}

			$execute = new ReflectionMethod( WP_DuckDB_Driver::class, 'execute_duckdb_query' );
			$emit    = new ReflectionMethod( WP_DuckDB_Driver::class, 'emit_runtime_counters_summary' );
			if ( PHP_VERSION_ID < 80100 ) {
				$execute->setAccessible( true );
				$emit->setAccessible( true );
			}

			$execute->invoke( $driver, 'SELECT 123 AS value', 'Runtime counter test context' );
			$emit->invoke( null, 'test' );

			$log = file_get_contents( $log_file );
			$this->assertStringContainsString( 'WP_DUCKDB_RUNTIME_COUNTERS_SUMMARY reason=test', $log );
			$this->assertStringContainsString( 'native_queries=1', $log );
			$this->assertStringContainsString( 'WP_DUCKDB_RUNTIME_COUNTER rank=', $log );
			$this->assertStringContainsString( 'name=native_query', $log );
			$this->assertStringContainsString( 'WP_DUCKDB_RUNTIME_CONTEXT_TOP rank=', $log );
			$this->assertStringContainsString( 'context=Runtime counter test context', $log );
			$this->assertStringContainsString( 'WP_DUCKDB_RUNTIME_NATIVE_TOP rank=', $log );
			$this->assertStringContainsString( 'shape=SELECT ? AS value', $log );
		} finally {
			if ( false === $previous_env ) {
				putenv( 'WP_DUCKDB_RUNTIME_COUNTERS' );
			} else {
				putenv( 'WP_DUCKDB_RUNTIME_COUNTERS=' . $previous_env );
			}
			ini_set( 'log_errors', false === $previous_log_errors ? '' : $previous_log_errors );
			ini_set( 'error_log', false === $previous_error_log ? '' : $previous_error_log );
			$this->reset_duckdb_runtime_counters_for_test();
			if ( is_string( $log_file ) && file_exists( $log_file ) ) {
				unlink( $log_file );
			}
		}
	}

	private function new_byte_safe_duckdb_driver(): WP_DuckDB_Driver {
		$connection = new class() extends WP_DuckDB_Connection {
			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				throw new RuntimeException( 'Unexpected DuckDB query: ' . $sql );
			}
		};
		$driver     = ( new ReflectionClass( WP_DuckDB_Driver::class ) )->newInstanceWithoutConstructor();

		foreach (
			array(
				'mysql_version'    => WP_DuckDB_Driver::DEFAULT_MYSQL_VERSION,
				'connection'       => $connection,
				'database'         => 'wp',
				'current_database' => 'wp',
			) as $property => $value
		) {
			$reflection_property = new ReflectionProperty( WP_DuckDB_Driver::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection_property->setAccessible( true );
			}
			$reflection_property->setValue( $driver, $value );
		}

		$grammar = new ReflectionProperty( WP_DuckDB_Driver::class, 'mysql_grammar' );
		if ( PHP_VERSION_ID < 80100 ) {
			$grammar->setAccessible( true );
		}
		$grammar->setValue( null, new WP_Parser_Grammar( require WP_DuckDB_Driver::MYSQL_GRAMMAR_PATH ) );

		return $driver;
	}

	private function reset_duckdb_runtime_counters_for_test(): void {
		foreach (
			array(
				'runtime_counters_enabled'             => null,
				'runtime_counters_shutdown_registered' => false,
				'runtime_counters'                     => array(
					'counters'        => array(),
					'native_contexts' => array(),
					'native_shapes'   => array(),
				),
			) as $property => $value
		) {
			$reflection_property = new ReflectionProperty( WP_DuckDB_Driver::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection_property->setAccessible( true );
			}
			$reflection_property->setValue( null, $value );
		}
	}

	public function test_auto_increment_insert_id_falls_back_to_max_when_currval_is_unavailable(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $queries = array();

			private $max_reads = 0;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;

				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( false !== strpos( $sql, "table_type = 'LOCAL TEMPORARY'" ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array() );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array( array( 'wp_users' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'column_name' ), array( array( 'ID' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
					throw new WP_DuckDB_Driver_Exception( 'currval unavailable' );
				}

				if ( 'SELECT MAX("ID") AS max_value FROM "wp_users"' === $sql ) {
					++$this->max_reads;
					return new WP_DuckDB_Result_Statement(
						array( 'max_value' ),
						array( array( 1 === $this->max_reads ? 1 : 2 ) )
					);
				}

				if ( 'INSERT INTO "wp_users" ("display_name") VALUES (\'Walter Sobchak\')' === $sql ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 1 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );
		$mysql_sql  = "INSERT INTO wp_users (display_name) VALUES ('Walter Sobchak')";
		$duckdb_sql = 'INSERT INTO "wp_users" ("display_name") VALUES (\'Walter Sobchak\')';

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
		}
		$tokens = $tokenize->invoke( $driver, $mysql_sql );

		$execute = new ReflectionMethod( WP_DuckDB_Driver::class, 'execute_auto_increment_write' );
		if ( PHP_VERSION_ID < 80100 ) {
			$execute->setAccessible( true );
		}
		$result = $execute->invoke(
			$driver,
			'wp_users',
			$duckdb_sql,
			'Failed to execute DuckDB INSERT',
			$tokens,
			2
		);

		$this->assertSame( 1, $result->rowCount() );
		$this->assertSame( 2, $driver->get_insert_id() );
		$this->assertSame(
			2,
			substr_count( implode( "\n", $connection->queries ), 'SELECT MAX("ID") AS max_value FROM "wp_users"' )
		);
	}

	public function test_omitted_auto_increment_insert_in_active_transaction_skips_uninitialized_currval_probe(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $queries       = array();
			public $currval_reads = 0;
			public $max_reads     = 0;

			private $aborted        = false;
			private $inserted       = false;
			private $in_transaction = false;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;

				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( 0 === strpos( $sql, 'CREATE TABLE IF NOT EXISTS "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( 0 === strpos( $sql, 'CREATE TABLE IF NOT EXISTS "__wp_duckdb_table_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( false !== strpos( $sql, "table_type = 'LOCAL TEMPORARY'" ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array() );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array( array( 'wp_usermeta' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name, engine, row_format, table_collation, table_comment, create_options, create_time FROM "__wp_duckdb_table_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement(
						array( 'table_name', 'engine', 'row_format', 'table_collation', 'table_comment', 'create_options', 'create_time' ),
						array(
							array( 'wp_usermeta', 'InnoDB', 'Dynamic', 'utf8mb4_bin', '', '', '2026-06-27 00:00:00' ),
						)
					);
				}

				if ( 0 === strpos( $sql, 'SELECT ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement(
						array( 'ordinal_position', 'column_name', 'column_type', 'is_nullable', 'column_key', 'column_default', 'extra', 'collation_name', 'comment' ),
						array(
							array( 1, 'umeta_id', 'bigint(20) unsigned', 'NO', 'PRI', null, 'auto_increment', null, '' ),
							array( 2, 'user_id', 'bigint(20) unsigned', 'NO', '', '0', '', null, '' ),
							array( 3, 'meta_key', 'varchar(255)', 'YES', 'MUL', null, '', null, '' ),
							array( 4, 'meta_value', 'longtext', 'YES', '', null, '', null, '' ),
						)
					);
				}

				if ( 0 === strpos( $sql, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'column_name' ), array( array( 'umeta_id' ) ) );
				}

				if ( 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' === $sql ) {
					++$this->max_reads;
					return new WP_DuckDB_Result_Statement(
						array( 'max_value' ),
						array( array( 1 === $this->max_reads ? 17 : 18 ) )
					);
				}

				if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
					++$this->currval_reads;
					if ( ! $this->inserted ) {
						$this->aborted = true;
						throw new WP_DuckDB_Driver_Exception( 'currval unavailable' );
					}

					return new WP_DuckDB_Result_Statement( array( 'currval' ), array( array( 18 ) ) );
				}

				if (
					0 === strpos( $sql, 'INSERT INTO "wp_usermeta"' )
					&& false !== strpos( $sql, '"user_id"' )
					&& false !== strpos( $sql, '"meta_key"' )
					&& false !== strpos( $sql, '"meta_value"' )
					&& false === strpos( $sql, '"umeta_id"' )
				) {
					if ( $this->aborted ) {
						throw new WP_DuckDB_Driver_Exception( 'DuckDB query failed: TransactionContext Error: Current transaction is aborted (please ROLLBACK)' );
					}

					$this->inserted = true;
					return new WP_DuckDB_Result_Statement( array(), array(), 1 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}

			public function beginTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				$this->in_transaction = true;
				return true;
			}

			public function inTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return $this->in_transaction;
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );

		$driver->query( 'BEGIN' );
		$insert = $driver->query(
			"INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'wp_persisted_preferences', 'a:0:{}')"
		);

		$currval_index = null;
		$insert_index  = null;
		foreach ( $connection->queries as $index => $sql ) {
			if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
				$currval_index = $index;
			}
			if ( 0 === strpos( $sql, 'INSERT INTO "wp_usermeta"' ) ) {
				$insert_index = $index;
			}
		}

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 18, $driver->get_insert_id() );
		$this->assertSame( 1, $connection->max_reads );
		$this->assertSame( 1, $connection->currval_reads );
		$this->assertIsInt( $currval_index );
		$this->assertIsInt( $insert_index );
		$this->assertGreaterThan( $insert_index, $currval_index );
		$this->assertTrue( $connection->inTransaction() );
	}

	public function test_auto_increment_insert_id_falls_back_to_max_when_currval_is_stale(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $queries = array();

			private $max_reads = 0;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;

				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( 0 === strpos( $sql, 'CREATE TABLE IF NOT EXISTS "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( 0 === strpos( $sql, 'CREATE TABLE IF NOT EXISTS "__wp_duckdb_table_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( false !== strpos( $sql, "table_type = 'LOCAL TEMPORARY'" ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array() );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array( array( 'wp_usermeta' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name, engine, row_format, table_collation, table_comment, create_options, create_time FROM "__wp_duckdb_table_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement(
						array( 'table_name', 'engine', 'row_format', 'table_collation', 'table_comment', 'create_options', 'create_time' ),
						array(
							array( 'wp_usermeta', 'InnoDB', 'Dynamic', 'utf8mb4_bin', '', '', '2026-06-27 00:00:00' ),
						)
					);
				}

				if ( 0 === strpos( $sql, 'SELECT ordinal_position, column_name, column_type, is_nullable, column_key, column_default, extra, collation_name, comment FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement(
						array( 'ordinal_position', 'column_name', 'column_type', 'is_nullable', 'column_key', 'column_default', 'extra', 'collation_name', 'comment' ),
						array(
							array( 1, 'umeta_id', 'bigint(20) unsigned', 'NO', 'PRI', null, 'auto_increment', null, '' ),
							array( 2, 'user_id', 'bigint(20) unsigned', 'NO', '', '0', '', null, '' ),
							array( 3, 'meta_key', 'varchar(255)', 'YES', 'MUL', null, '', null, '' ),
							array( 4, 'meta_value', 'longtext', 'YES', '', null, '', null, '' ),
						)
					);
				}

				if ( 0 === strpos( $sql, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'column_name' ), array( array( 'umeta_id' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'currval' ), array( array( 17 ) ) );
				}

				if ( 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' === $sql ) {
					++$this->max_reads;
					return new WP_DuckDB_Result_Statement(
						array( 'max_value' ),
						array( array( 1 === $this->max_reads ? 17 : 18 ) )
					);
				}

				if (
					0 === strpos( $sql, 'INSERT INTO "wp_usermeta"' )
					&& false !== strpos( $sql, '"user_id"' )
					&& false !== strpos( $sql, '"meta_key"' )
					&& false !== strpos( $sql, '"meta_value"' )
					&& false === strpos( $sql, '"umeta_id"' )
				) {
					return new WP_DuckDB_Result_Statement( array(), array(), 1 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );
		$insert     = $driver->query(
			"INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'wp_persisted_preferences', 'a:0:{}')"
		);
		$query_log  = implode( "\n", $connection->queries );
		$insert_sql = array_values(
			array_filter(
				$connection->queries,
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'INSERT INTO "wp_usermeta"' );
				}
			)
		);

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 18, $driver->get_insert_id() );
		$this->assertCount( 1, $insert_sql );
		$this->assertStringNotContainsString( '"umeta_id"', $insert_sql[0] );
		$this->assertSame(
			2,
			substr_count( $query_log, 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' )
		);
	}

	public function test_auto_increment_insert_id_recovers_when_row_count_is_zero(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $queries = array();

			private $max_reads = 0;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;

				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( false !== strpos( $sql, "table_type = 'LOCAL TEMPORARY'" ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array() );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array( array( 'wp_usermeta' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'column_name' ), array( array( 'umeta_id' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
					throw new WP_DuckDB_Driver_Exception( 'currval unavailable' );
				}

				if ( 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' === $sql ) {
					++$this->max_reads;
					return new WP_DuckDB_Result_Statement(
						array( 'max_value' ),
						array( array( 1 === $this->max_reads ? 0 : 1 ) )
					);
				}

				if ( 'INSERT INTO "wp_usermeta" ("user_id", "meta_key", "meta_value") VALUES (1, \'wp_persisted_preferences\', \'a:0:{}\')' === $sql ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );
		$mysql_sql  = "INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'wp_persisted_preferences', 'a:0:{}')";
		$duckdb_sql = 'INSERT INTO "wp_usermeta" ("user_id", "meta_key", "meta_value") VALUES (1, \'wp_persisted_preferences\', \'a:0:{}\')';

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
		}
		$tokens = $tokenize->invoke( $driver, $mysql_sql );

		$execute = new ReflectionMethod( WP_DuckDB_Driver::class, 'execute_auto_increment_write' );
		if ( PHP_VERSION_ID < 80100 ) {
			$execute->setAccessible( true );
		}
		$result = $execute->invoke(
			$driver,
			'wp_usermeta',
			$duckdb_sql,
			'Failed to execute DuckDB INSERT',
			$tokens,
			2
		);

		$this->assertSame( 1, $result->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$this->assertSame(
			2,
			substr_count( implode( "\n", $connection->queries ), 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' )
		);
	}

	public function test_auto_increment_insert_id_recovers_from_physical_nextval_default_when_metadata_is_stale(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $queries = array();

			private $max_reads = 0;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;

				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				if ( false !== strpos( $sql, "table_type = 'LOCAL TEMPORARY'" ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array() );
				}

				if ( 0 === strpos( $sql, 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'table_name' ), array( array( 'wp_usermeta' ) ) );
				}

				if ( 0 === strpos( $sql, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'column_name' ), array() );
				}

				if ( "SELECT cid, name, type, \"notnull\", dflt_value, pk FROM pragma_table_info('wp_usermeta') ORDER BY cid" === $sql ) {
					return new WP_DuckDB_Result_Statement(
						array( 'cid', 'name', 'type', 'notnull', 'dflt_value', 'pk' ),
						array(
							array( 0, 'umeta_id', 'BIGINT', true, "nextval('__wp_duckdb_auto_increment_existing')", 1 ),
							array( 1, 'user_id', 'BIGINT', false, null, 0 ),
							array( 2, 'meta_key', 'VARCHAR', false, null, 0 ),
							array( 3, 'meta_value', 'VARCHAR', false, null, 0 ),
						)
					);
				}

				if ( 0 === strpos( $sql, 'SELECT currval(' ) ) {
					return new WP_DuckDB_Result_Statement( array( 'currval' ), array( array( 18 ) ) );
				}

				if ( 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' === $sql ) {
					++$this->max_reads;
					return new WP_DuckDB_Result_Statement( array( 'max_value' ), array( array( 17 ) ) );
				}

				if ( 'INSERT INTO "wp_usermeta" ("user_id", "meta_key", "meta_value") VALUES (1, \'wp_persisted_preferences\', \'a:0:{}\')' === $sql ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 1 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );
		$mysql_sql  = "INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'wp_persisted_preferences', 'a:0:{}')";
		$duckdb_sql = 'INSERT INTO "wp_usermeta" ("user_id", "meta_key", "meta_value") VALUES (1, \'wp_persisted_preferences\', \'a:0:{}\')';

		$tokenize = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
		}
		$tokens = $tokenize->invoke( $driver, $mysql_sql );

		$execute = new ReflectionMethod( WP_DuckDB_Driver::class, 'execute_auto_increment_write' );
		if ( PHP_VERSION_ID < 80100 ) {
			$execute->setAccessible( true );
		}
		$result = $execute->invoke(
			$driver,
			'wp_usermeta',
			$duckdb_sql,
			'Failed to execute DuckDB INSERT',
			$tokens,
			2
		);

		$this->assertSame( 1, $result->rowCount() );
		$this->assertSame( 18, $driver->get_insert_id() );
		$this->assertSame(
			1,
			substr_count( implode( "\n", $connection->queries ), "SELECT cid, name, type, \"notnull\", dflt_value, pk FROM pragma_table_info('wp_usermeta') ORDER BY cid" )
		);
		$this->assertSame(
			1,
			substr_count( implode( "\n", $connection->queries ), 'SELECT MAX("umeta_id") AS max_value FROM "wp_usermeta"' )
		);
	}

	public function test_record_found_rows_from_result_preserves_column_metadata(): void {
		$driver = ( new ReflectionClass( WP_DuckDB_Driver::class ) )->newInstanceWithoutConstructor();
		$source = new WP_DuckDB_Result_Statement(
			array( 'post_id', 'post_title' ),
			array(
				array( 1, 'Hello' ),
				array( 2, 'World' ),
			),
			0,
			array(
				array(
					'name'              => 'post_id',
					'table'             => 'p',
					'mysqli:orgname'    => 'ID',
					'mysqli:orgtable'   => 'wp_posts',
					'mysqli:db'         => 'wordpress_test',
					'len'               => 20,
					'mysqli:charsetnr'  => 63,
					'mysqli:type'       => 8,
					'mysqli:custom_key' => 'preserved',
				),
				array(
					'name'             => 'post_title',
					'table'            => 'p',
					'mysqli:orgname'   => 'post_title',
					'mysqli:orgtable'  => 'wp_posts',
					'mysqli:db'        => 'wordpress_test',
					'len'              => 764,
					'mysqli:charsetnr' => 255,
					'mysqli:type'      => 253,
				),
			)
		);

		$method = new ReflectionMethod( WP_DuckDB_Driver::class, 'record_found_rows_from_result' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$result = $method->invoke( $driver, $source );

		$found_rows = new ReflectionProperty( WP_DuckDB_Driver::class, 'found_rows' );
		if ( PHP_VERSION_ID < 80100 ) {
			$found_rows->setAccessible( true );
		}

		$this->assertSame( 2, $found_rows->getValue( $driver ) );
		$this->assertSame(
			array(
				array(
					'post_id'    => 1,
					'post_title' => 'Hello',
				),
				array(
					'post_id'    => 2,
					'post_title' => 'World',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( $source->getColumnMeta( 0 ), $result->getColumnMeta( 0 ) );
		$this->assertSame( $source->getColumnMeta( 1 ), $result->getColumnMeta( 1 ) );
	}

	public function test_translated_driver_execution_failure_preserves_context_and_previous_chain(): void {
		$native_failure = new RuntimeException( 'native create failure', 321 );
		$connection     = new WP_DuckDB_Connection(
			array(
				'duckdb' => new class( $native_failure ) {
					private $native_failure;

					public function __construct( Throwable $native_failure ) {
						$this->native_failure = $native_failure;
					}

					public function query( string $sql ) {
						if ( 0 === strpos( $sql, 'CREATE TABLE "broken"' ) ) {
							throw $this->native_failure;
						}

						return $this->create_native_result( array( 'Count' ), array() );
					}

					private function create_native_result( array $columns, array $rows ) {
						return new class( $columns, $rows ) {
							private $columns;
							private $rows;

							public function __construct( array $columns, array $rows ) {
								$this->columns = $columns;
								$this->rows    = $rows;
							}

							public function columnNames(): ArrayIterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
								return new ArrayIterator( $this->columns );
							}

							public function rows( bool $assoc ): array {
								$rows = array();
								foreach ( $this->rows as $row ) {
									$rows[] = array_combine( $this->columns, $row );
								}
								return $rows;
							}
						};
					}
				},
			)
		);
		$driver         = new WP_DuckDB_Driver( array( 'connection' => $connection ) );

		try {
			$driver->query( 'CREATE TABLE broken (id INT)' );
			$this->fail( 'Expected WP_DuckDB_Driver_Exception.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame( 'HY000', $e->getCode() );
			$this->assertStringStartsWith( 'Failed to create DuckDB table: DuckDB query failed:', $e->getMessage() );
			$this->assertSame( 1, substr_count( $e->getMessage(), 'Failed to create DuckDB table:' ) );
			$this->assertInstanceOf( WP_DuckDB_Driver_Exception::class, $e->getPrevious() );
			$this->assertSame( 'HY000', $e->getPrevious()->getCode() );
			$this->assertStringStartsWith( 'DuckDB query failed: native create failure', $e->getPrevious()->getMessage() );
			$this->assertSame( $native_failure, $e->getPrevious()->getPrevious() );
		}
	}

	public function test_wrapped_duckdb_query_failure_rolls_back_active_transaction(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public $rollback_count = 0;

			private $in_transaction = false;

			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				if ( 0 === strpos( $sql, 'SELECT ' ) ) {
					throw new WP_DuckDB_Driver_Exception( 'Unsupported DuckDB MySQL-emulation SELECT statement: DuckDB query failed: Invalid Input Error: invalid unicode' );
				}

				return new WP_DuckDB_Result_Statement( array(), array(), 0 );
			}

			public function beginTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				$this->in_transaction = true;
				return true;
			}

			public function rollback(): bool {
				++$this->rollback_count;
				$this->in_transaction = false;
				return true;
			}

			public function inTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				return $this->in_transaction;
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );

		$driver->query( 'BEGIN' );
		$this->assertTrue( $connection->inTransaction() );

		try {
			$driver->query( 'SELECT 1 AS wrapped_failure' );
			$this->fail( 'Expected wrapped SELECT failure.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( ': DuckDB query failed: Invalid Input Error:', $e->getMessage() );
		}

		$this->assertSame( 1, $connection->rollback_count );
		$this->assertFalse( $connection->inTransaction() );
	}

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

	public function test_no_argument_select_stored_procedure_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp_tests',
			)
		);
		$driver->query(
			'CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_title VARCHAR(100),
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		$driver->query( 'DROP PROCEDURE IF EXISTS `test_mysqli_flush_sync_procedure`' );
		$driver->query(
			'CREATE PROCEDURE `test_mysqli_flush_sync_procedure`() BEGIN
				SELECT ID, post_title FROM `wp_posts` ORDER BY ID LIMIT 1;
			END'
		);

		$show_rows = $driver->query( 'SHOW CREATE PROCEDURE `test_mysqli_flush_sync_procedure`' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 1, $show_rows );
		$this->assertSame( 'test_mysqli_flush_sync_procedure', $show_rows[0]['Procedure'] );
		$this->assertStringContainsString( 'CREATE PROCEDURE `test_mysqli_flush_sync_procedure`()', $show_rows[0]['Create Procedure'] );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query(
			"INSERT INTO wp_posts (ID, post_title) VALUES
				(2, 'second'),
				(1, 'first')"
		);

		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'first',
				),
			),
			$driver->query( 'CALL `test_mysqli_flush_sync_procedure`' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'first',
				),
			),
			$driver->query( 'CALL `wp_tests`.`test_mysqli_flush_sync_procedure`()' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP PROCEDURE IF EXISTS `test_mysqli_flush_sync_procedure`' );
		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( "Unknown procedure 'wp_tests.test_mysqli_flush_sync_procedure' in DuckDB driver." );
		$driver->query( 'CALL `test_mysqli_flush_sync_procedure`' );
	}

	public function test_stored_procedure_emulation_rejects_unsupported_bodies(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$this->expectException( WP_DuckDB_Driver_Exception::class );
		$this->expectExceptionMessage( 'Only no-argument single-SELECT procedures are supported.' );
		$driver->query(
			'CREATE PROCEDURE unsupported_body() BEGIN
				SET @value = 1;
			END'
		);
	}

	public function test_select_posts_wildcard_group_by_primary_key_expands_group_by(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				post_title TEXT NOT NULL,
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_posts (ID, post_author, post_title) VALUES
				(1, 10, 'first'),
				(2, 20, 'second')"
		);

		$rows = $driver->query(
			'SELECT wp_posts.* FROM wp_posts GROUP BY wp_posts.ID ORDER BY wp_posts.ID'
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'ID'          => 1,
					'post_author' => 10,
					'post_title'  => 'first',
				),
				array(
					'ID'          => 2,
					'post_author' => 20,
					'post_title'  => 'second',
				),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString(
			'GROUP BY wp_posts.ID, "wp_posts"."post_author", "wp_posts"."post_title"',
			$select_sql
		);
	}

	public function test_select_primary_key_group_by_orders_by_functionally_dependent_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_posts (ID, post_date, post_type, post_status) VALUES
				(1, '2026-01-01 00:00:00', 'wp_global_styles', 'publish'),
				(2, '2026-02-01 00:00:00', 'wp_global_styles', 'publish'),
				(3, '2026-03-01 00:00:00', 'post', 'publish')"
		);

		$rows = $driver->query(
			"SELECT wp_posts.ID
			FROM wp_posts
			WHERE 1=1
				AND wp_posts.post_type = 'wp_global_styles'
				AND ((wp_posts.post_status = 'publish'))
			GROUP BY wp_posts.ID
			ORDER BY wp_posts.post_date DESC
			LIMIT 0, 1"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'ID' => 2 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString(
			'GROUP BY wp_posts.ID, "wp_posts"."post_date"',
			$select_sql
		);
	}

	public function test_select_posts_group_by_primary_key_date_order_appends_id_tiebreaker(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID),
				KEY type_status_date (post_type, post_status, post_date, ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
				(1, '2026-06-29 09:00:00', 'post', 'publish'),
				(2, '2026-06-29 09:00:00', 'post', 'publish'),
				(3, '2026-06-29 09:00:00', 'post', 'publish')"
		);

		$rows = $driver->query(
			"SELECT wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'post'
				AND wptests_posts.post_status = 'publish'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'ID' => 3 ),
				array( 'ID' => 2 ),
				array( 'ID' => 1 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString(
			'ORDER BY wptests_posts.post_date DESC, "wptests_posts"."ID" DESC',
			$select_sql
		);
	}

	public function test_select_posts_wildcard_joined_group_by_primary_key_expands_group_by(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_joined_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT wptests_posts.*
			FROM wptests_posts
			INNER JOIN wptests_postmeta
				ON (wptests_posts.ID = wptests_postmeta.post_id)
			WHERE 1=1
				AND wptests_posts.post_type = 'page'
				AND wptests_postmeta.meta_key = '_wp_page_template'
				AND wptests_postmeta.meta_value = 'default'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'ID'          => 2,
					'post_author' => 20,
					'post_date'   => '2026-02-01 00:00:00',
					'post_title'  => 'second',
					'post_type'   => 'page',
				),
				array(
					'ID'          => 1,
					'post_author' => 10,
					'post_date'   => '2026-01-01 00:00:00',
					'post_title'  => 'first',
					'post_type'   => 'page',
				),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString(
			'GROUP BY wptests_posts.ID, "wptests_posts"."post_author", "wptests_posts"."post_date", "wptests_posts"."post_title", "wptests_posts"."post_type"',
			$select_sql
		);
	}

	public function test_select_joined_group_by_primary_key_rejects_joined_table_projection(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_joined_group_by_tables( $driver );

		try {
			$driver->query(
				"SELECT wptests_posts.ID, wptests_postmeta.meta_value
				FROM wptests_posts
				INNER JOIN wptests_postmeta
					ON (wptests_posts.ID = wptests_postmeta.post_id)
				WHERE wptests_postmeta.meta_key = '_wp_page_template'
				GROUP BY wptests_posts.ID"
			);
			$this->fail( 'Expected WP_DuckDB_Driver_Exception.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Unsupported DuckDB MySQL-emulation SELECT statement', $e->getMessage() );
		}

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringNotContainsString( '"wptests_posts"."post_author"', $select_sql );
	}

	public function test_select_left_join_group_by_primary_key_orders_by_functionally_dependent_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_left_join_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT wptests_posts.ID
			FROM wptests_posts
			LEFT JOIN wptests_term_relationships
				ON (wptests_posts.ID = wptests_term_relationships.object_id)
			WHERE wptests_posts.post_type = 'post'
				AND wptests_posts.post_status = 'publish'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date ASC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'ID' => 1 ),
				array( 'ID' => 2 ),
				array( 'ID' => 3 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString(
			'GROUP BY wptests_posts.ID, "wptests_posts"."post_date"',
			$select_sql
		);
	}

	public function test_sql_calc_found_rows_left_join_group_by_primary_key_expands_group_by_for_count(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_left_join_group_by_tables( $driver );

		$result = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			LEFT OUTER JOIN wptests_term_relationships
				ON (wptests_posts.ID = wptests_term_relationships.object_id)
			WHERE wptests_posts.post_type = 'post'
				AND wptests_posts.post_status = 'publish'
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date ASC
			LIMIT 0, 2"
		);

		$this->assertSame(
			array(
				array( 'ID' => 1 ),
				array( 'ID' => 2 ),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_select_terms_aggregate_group_by_primary_key_wraps_joined_projections(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT DISTINCT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, COUNT(p.post_type) AS count
			FROM wptests_terms AS t
				INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN wptests_posts AS p ON p.ID = r.object_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND (p.post_type = 'post' OR p.post_type IS NULL)
				AND (p.post_status = 'publish')
			GROUP BY t.term_id
			ORDER BY t.name ASC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'term_id'          => 1,
					'term_taxonomy_id' => 101,
					'taxonomy'         => 'wptests_tax',
					'description'      => 'First description',
					'parent'           => 0,
					'count'            => 1,
				),
				array(
					'term_id'          => 2,
					'term_taxonomy_id' => 102,
					'taxonomy'         => 'wptests_tax',
					'description'      => 'Second description',
					'parent'           => 0,
					'count'            => 1,
				),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(tt.term_taxonomy_id) AS "term_taxonomy_id"', $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(tt.taxonomy) AS "taxonomy"', $select_sql );
		$this->assertStringContainsString( 'GROUP BY t.term_id, "t"."name"', $select_sql );
	}

	public function test_select_split_shared_term_probe_group_by_primary_key_wraps_joined_projection(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );

		$rows = $driver->query(
			'SELECT tt.term_id, t.*, count(*) AS term_tt_count
			FROM wptests_term_taxonomy tt
			LEFT JOIN wptests_terms t ON t.term_id = tt.term_id
			GROUP BY t.term_id
			HAVING term_tt_count > 1
			LIMIT 1'
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'term_id'       => 3,
					'name'          => 'Shared',
					'slug'          => 'shared',
					'term_group'    => 0,
					'term_tt_count' => 2,
				),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(tt.term_id) AS "term_id"', $select_sql );
		$this->assertStringContainsString( 'GROUP BY t.term_id, "t"."name", "t"."slug", "t"."term_group"', $select_sql );
	}

	public function test_sql_calc_found_rows_meta_query_regexp_and_numeric_like_counts(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_meta_query_found_rows_tables( $driver );

		$regexp = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND ( wptests_postmeta.meta_key = 'foo' AND wptests_postmeta.meta_value REGEXP 'z$' )
				AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'ID' => 1 ) ), $regexp );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$not_regexp = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND ( wptests_postmeta.meta_key = 'foo' AND wptests_postmeta.meta_value NOT REGEXP 'z$' )
				AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'ID' => 2 ) ), $not_regexp );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$numeric_like = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND ( wptests_postmeta.meta_key = 'decimal_value' AND CAST(wptests_postmeta.meta_value AS DECIMAL(10,2)) LIKE '%.3%' )
				AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'ID' => 1 ) ), $numeric_like );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$numeric_not_like = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
			WHERE 1=1
				AND ( wptests_postmeta.meta_key = 'decimal_value' AND CAST(wptests_postmeta.meta_id AS SIGNED) NOT LIKE '3' )
				AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'ID' => 2 ) ), $numeric_not_like );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_numeric_cast_like_predicates_tolerate_non_numeric_text(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
				(1, 1, 'num', '10'),
				(2, 1, 'num', '010'),
				(3, 1, 'num', '1780093651'),
				(4, 1, 'num', 'abc'),
				(5, 1, 'num', '10.50'),
				(6, 1, 'num', '-7'),
				(7, 1, 'num', NULL),
				(8, 1, 'num', '10abc'),
				(9, 1, 'num', '10.50x'),
				(10, 1, 'num', ' 11x'),
				(11, 1, 'num', '')"
		);

		$this->assertSame(
			array( array( 'meta_id' => 1 ), array( 'meta_id' => 2 ), array( 'meta_id' => 5 ), array( 'meta_id' => 8 ), array( 'meta_id' => 9 ) ),
			$driver->query(
				"SELECT meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS SIGNED) LIKE '10%'
				ORDER BY meta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$backslash = chr( 92 );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS UNSIGNED) LIKE '10{$backslash}%'
				ORDER BY meta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'meta_id' => 1 ), array( 'meta_id' => 2 ), array( 'meta_id' => 5 ), array( 'meta_id' => 8 ), array( 'meta_id' => 9 ) ),
			$driver->query(
				"SELECT meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS UNSIGNED) LIKE '10%'
				ORDER BY meta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'meta_id' => 5 ), array( 'meta_id' => 9 ) ),
			$driver->query(
				"SELECT meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS DECIMAL(10,2)) LIKE '10.5%'
				ORDER BY meta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'meta_id' => 3 ), array( 'meta_id' => 4 ), array( 'meta_id' => 6 ), array( 'meta_id' => 10 ), array( 'meta_id' => 11 ) ),
			$driver->query(
				"SELECT meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS SIGNED) NOT LIKE '10%'
				ORDER BY meta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'meta_id' => 1 ), array( 'meta_id' => 2 ) ),
			$driver->query(
				"SELECT SQL_CALC_FOUND_ROWS meta_id
				FROM wptests_postmeta
				WHERE CAST(meta_value AS SIGNED) LIKE '10%'
				ORDER BY meta_id
				LIMIT 2"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 5 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_select_comments_group_by_primary_key_orders_by_joined_meta_value(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_comment_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT wptests_comments.comment_ID
			 FROM wptests_comments INNER JOIN wptests_commentmeta ON ( wptests_comments.comment_ID = wptests_commentmeta.comment_id )
			 WHERE ( ( comment_approved = '0' OR comment_approved = '1' ) ) AND (
				wptests_commentmeta.meta_key = 'key'
			 )
			 GROUP BY wptests_comments.comment_ID
			 ORDER BY wptests_commentmeta.meta_value DESC, wptests_comments.comment_ID DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'comment_ID' => 2 ),
				array( 'comment_ID' => 3 ),
				array( 'comment_ID' => 1 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(wptests_commentmeta.meta_value) DESC', $select_sql );
		$this->assertStringContainsString( 'ORDER BY ANY_VALUE(wptests_commentmeta.meta_value) DESC, wptests_comments.comment_ID DESC', $select_sql );
	}

	public function test_select_comments_group_by_primary_key_orders_by_cast_joined_meta_value(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_comment_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT wptests_comments.comment_ID
			 FROM wptests_comments INNER JOIN wptests_commentmeta ON ( wptests_comments.comment_ID = wptests_commentmeta.comment_id )
			 WHERE ( ( comment_approved = '0' OR comment_approved = '1' ) ) AND (
				wptests_commentmeta.meta_key = 'foo'
			 )
			 GROUP BY wptests_comments.comment_ID
			 ORDER BY wptests_comments.comment_date ASC, CAST(wptests_commentmeta.meta_value AS CHAR) ASC, wptests_comments.comment_ID ASC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'comment_ID' => 1 ),
				array( 'comment_ID' => 2 ),
				array( 'comment_ID' => 3 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString( 'GROUP BY wptests_comments.comment_ID, "wptests_comments"."comment_date"', $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(CAST(wptests_commentmeta.meta_value AS VARCHAR)) ASC', $select_sql );
	}

	public function test_select_comments_group_by_primary_key_orders_by_multiple_cast_joined_meta_values(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_comment_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT wptests_comments.comment_ID
			 FROM wptests_comments
				INNER JOIN wptests_commentmeta ON ( wptests_comments.comment_ID = wptests_commentmeta.comment_id )
				INNER JOIN wptests_commentmeta AS mt1 ON ( wptests_comments.comment_ID = mt1.comment_id )
			 WHERE ( ( comment_approved = '0' OR comment_approved = '1' ) ) AND (
				wptests_commentmeta.meta_key = 'foo'
				AND
				mt1.meta_key = 'bar'
			 )
			 GROUP BY wptests_comments.comment_ID
			 ORDER BY CAST(wptests_commentmeta.meta_value AS CHAR) ASC, CAST(mt1.meta_value AS CHAR) DESC, wptests_comments.comment_ID DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array( 'comment_ID' => 1 ),
				array( 'comment_ID' => 2 ),
				array( 'comment_ID' => 3 ),
			),
			$rows
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(CAST(wptests_commentmeta.meta_value AS VARCHAR)) ASC', $select_sql );
		$this->assertStringContainsString( 'ANY_VALUE(CAST(mt1.meta_value AS VARCHAR)) DESC', $select_sql );
	}

	public function test_select_count_strips_irrelevant_order_by(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$this->create_wordpress_comment_group_by_tables( $driver );

		$rows = $driver->query(
			"SELECT COUNT(*)
			 FROM wptests_comments
			 WHERE ( ( comment_approved = '0' OR comment_approved = '1' ) )
			 ORDER BY wptests_comments.comment_date_gmt ASC
			 LIMIT 0,3"
		)->fetchAll( PDO::FETCH_NUM );

		$this->assertSame( array( array( 3 ) ), $rows );

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$select_sql     = end( $duckdb_queries );

		$this->assertIsString( $select_sql );
		$this->assertStringNotContainsString( 'ORDER BY', $select_sql );
		$this->assertStringContainsString( 'LIMIT 3 OFFSET 0', $select_sql );
	}

	public function test_non_strict_wordpress_text_defaults_for_comments_and_options(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( "SET SESSION sql_mode = ''" );
		$driver->query(
			"CREATE TABLE wptests_comments (
				comment_ID bigint(20) unsigned NOT NULL auto_increment,
				comment_post_ID bigint(20) unsigned NOT NULL default '0',
				comment_author tinytext NOT NULL,
				comment_author_email varchar(100) NOT NULL default '',
				comment_author_url varchar(200) NOT NULL default '',
				comment_author_IP varchar(100) NOT NULL default '',
				comment_date datetime NOT NULL default '0000-00-00 00:00:00',
				comment_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				comment_content text NOT NULL,
				comment_karma int(11) NOT NULL default '0',
				comment_approved varchar(20) NOT NULL default '1',
				comment_agent varchar(255) NOT NULL default '',
				comment_type varchar(20) NOT NULL default 'comment',
				comment_parent bigint(20) unsigned NOT NULL default '0',
				user_id bigint(20) unsigned NOT NULL default '0',
				PRIMARY KEY (comment_ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( 'INSERT INTO wptests_comments (comment_ID) VALUES (1)' );

		$comment = $driver->query(
			'SELECT comment_ID, comment_author, comment_content FROM wptests_comments WHERE comment_ID = 1'
		)->fetch( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'comment_ID'      => 1,
				'comment_author'  => '',
				'comment_content' => '',
			),
			$comment
		);

		$driver->query(
			"CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "INSERT INTO wptests_options (option_id, option_name, option_value) VALUES (1, 'cron', 'payload')" );
		$driver->query( "UPDATE wptests_options SET option_value = NULL WHERE option_name = 'cron'" );

		$this->assertSame(
			'',
			$driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'cron'" )->fetchColumn()
		);
	}

	public function test_select_seeded_rand_literals_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$cases  = array(
			'SELECT RAND(0) AS r'     => 0.15522042769494,
			'SELECT RAND(1) AS r'     => 0.40540353712198,
			'SELECT RAND(5) AS r'     => 0.40613597483014,
			'SELECT RAND(NULL) AS r'  => 0.15522042769494,
			"SELECT RAND('5') AS r"   => 0.40613597483014,
			"SELECT RAND('3.9') AS r" => 0.15595286540310,
			"SELECT RAND('abc') AS r" => 0.15522042769494,
			'SELECT RAND(3.1) AS r'   => 0.90576975597606,
			'SELECT RAND(3.9) AS r'   => 0.15595286540310,
			'SELECT RAND(-1) AS r'    => 0.90503732199318,
		);

		foreach ( $cases as $sql => $expected ) {
			$row = $driver->query( $sql )->fetch( PDO::FETCH_ASSOC );
			$this->assertEqualsWithDelta( $expected, (float) $row['r'], 1e-12, $sql );
		}
	}

	public function test_select_seeded_rand_without_alias_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$row    = $driver->query( 'SELECT RAND(1)' )->fetch( PDO::FETCH_ASSOC );

		$this->assertArrayHasKey( 'RAND(1)', $row );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['RAND(1)'], 1e-12 );
	}

	public function test_select_seeded_rand_sequence_advances_per_statement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_rows (id INT)' );
		$driver->query( 'INSERT INTO seeded_rand_rows (id) VALUES (1), (2), (3)' );

		$first = $driver->query( 'SELECT id, RAND(3) AS r FROM seeded_rand_rows ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 3, $first );
		$this->assertEqualsWithDelta( 0.90576975597606, (float) $first[0]['r'], 1e-12 );
		$this->assertEqualsWithDelta( 0.37307905813035, (float) $first[1]['r'], 1e-12 );
		$this->assertEqualsWithDelta( 0.14808605345719, (float) $first[2]['r'], 1e-12 );

		$second = $driver->query( 'SELECT id, RAND(3) AS r FROM seeded_rand_rows ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( $first, $second );
	}

	public function test_select_order_by_seeded_rand_literal_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_order (id INT)' );
		$driver->query( 'INSERT INTO seeded_rand_order (id) VALUES (1), (2), (3), (4), (5)' );

		$first  = $driver->query( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1)' )->fetchAll( PDO::FETCH_COLUMN );
		$second = $driver->query( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1)' )->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( 5, 4, 3, 1, 2 ), array_map( 'intval', $first ) );
		$this->assertSame( $first, $second );

		$descending = $driver->query( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1) DESC' )->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( 2, 1, 3, 4, 5 ), array_map( 'intval', $descending ) );

		$limited = $driver->query( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1) DESC LIMIT 1, 2' )->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( 1, 3 ), array_map( 'intval', $limited ) );
	}

	public function test_sql_calc_found_rows_order_by_seeded_rand_literal_with_limit_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_type, post_status) VALUES
				(1, 'post', 'publish'),
				(2, 'post', 'publish'),
				(3, 'post', 'publish'),
				(4, 'post', 'publish'),
				(5, 'post', 'publish'),
				(6, 'post', 'publish'),
				(7, 'post', 'publish'),
				(8, 'post', 'publish'),
				(9, 'post', 'publish'),
				(10, 'post', 'publish'),
				(11, 'post', 'publish'),
				(12, 'post', 'publish')"
		);

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE 1=1
				AND ((wptests_posts.post_type = 'post'
				AND (wptests_posts.post_status = 'publish')))
			ORDER BY RAND(5) DESC
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_COLUMN );

		$this->assertSame( array( 6, 2, 7, 12, 8, 10, 1, 11, 5, 3 ), array_map( 'intval', $rows ) );
		$this->assertStringNotContainsString( 'RAND(', $this->lastDuckDBQuery( $driver ) );
		$this->assertStringNotContainsString( 'LIMIT', $this->lastDuckDBQuery( $driver ) );
		$this->assertSame(
			array( array( 'found_rows' => 12 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_select_seeded_rand_where_literal_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_where (id INT)' );
		$driver->query( 'INSERT INTO seeded_rand_where (id) VALUES (3), (1), (2)' );

		$filtered = $driver->query( 'SELECT id FROM seeded_rand_where WHERE RAND(1) < 0.5 ORDER BY id' )->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( 2, 3 ), array_map( 'intval', $filtered ) );

		$reversed = $driver->query( 'SELECT id FROM seeded_rand_where WHERE 0.5 > RAND(1) ORDER BY 1 DESC' )->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( 3, 2 ), array_map( 'intval', $reversed ) );
	}

	public function test_select_wildcard_seeded_rand_literal_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_wildcard (id INT, name VARCHAR(20))' );
		$driver->query( "INSERT INTO seeded_rand_wildcard (id, name) VALUES (1, 'a'), (2, 'b')" );

		$rows = $driver->query( 'SELECT *, RAND(1) AS r FROM seeded_rand_wildcard ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, (int) $rows[0]['id'] );
		$this->assertSame( 'a', $rows[0]['name'] );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['r'], 1e-12 );
		$this->assertSame( 2, (int) $rows[1]['id'] );
		$this->assertSame( 'b', $rows[1]['name'] );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $rows[1]['r'], 1e-12 );

		$rows = $driver->query( 'SELECT *, RAND(1) AS r, id AS explicit_id FROM seeded_rand_wildcard ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, (int) $rows[0]['id'] );
		$this->assertSame( 'a', $rows[0]['name'] );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['r'], 1e-12 );
		$this->assertSame( 1, (int) $rows[0]['explicit_id'] );
		$this->assertSame( 2, (int) $rows[1]['id'] );
		$this->assertSame( 'b', $rows[1]['name'] );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $rows[1]['r'], 1e-12 );
		$this->assertSame( 2, (int) $rows[1]['explicit_id'] );
	}

	public function test_select_seeded_rand_expression_seeds_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_expr (id INT, seed_text VARCHAR(20))' );
		$driver->query( "INSERT INTO seeded_rand_expr (id, seed_text) VALUES (1, '1'), (2, '2'), (3, '3')" );

		$rows = $driver->query( 'SELECT id, RAND(CAST(seed_text AS SIGNED)) AS r FROM seeded_rand_expr ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 3, $rows );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['r'], 1e-12 );
		$this->assertEqualsWithDelta( 0.65558664654902, (float) $rows[1]['r'], 1e-12 );
		$this->assertEqualsWithDelta( 0.90576975597606, (float) $rows[2]['r'], 1e-12 );

		$row = $driver->query( 'SELECT RAND(NULLIF(1, 1)) AS r' )->fetch( PDO::FETCH_ASSOC );
		$this->assertEqualsWithDelta( 0.15522042769494, (float) $row['r'], 1e-12 );
	}

	public function test_select_seeded_rand_expression_without_alias_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$row    = $driver->query( 'SELECT RAND(CAST(1 AS SIGNED))' )->fetch( PDO::FETCH_ASSOC );

		$this->assertArrayHasKey( 'RAND(CAST(1 AS SIGNED))', $row );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['RAND(CAST(1 AS SIGNED))'], 1e-12 );
	}

	public function test_select_seeded_rand_call_sites_share_statement_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$row    = $driver->query( 'SELECT RAND(1) AS a, RAND(1) AS b' )->fetch( PDO::FETCH_ASSOC );

		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['a'], 1e-12 );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $row['b'], 1e-12 );
	}

	public function test_select_seeded_and_unseeded_rand_are_independent(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$run1   = $driver->query( 'SELECT RAND(1) AS seeded, RAND() AS unseeded' )->fetch( PDO::FETCH_ASSOC );
		$run2   = $driver->query( 'SELECT RAND(1) AS seeded, RAND() AS unseeded' )->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( (float) $run1['seeded'], (float) $run2['seeded'] );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $run1['seeded'], 1e-12 );
		foreach ( array( $run1['unseeded'], $run2['unseeded'] ) as $value ) {
			$this->assertGreaterThanOrEqual( 0.0, (float) $value );
			$this->assertLessThan( 1.0, (float) $value );
		}
	}

	public function test_insert_values_seeded_rand_literals_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_values (id INT, value DOUBLE, other DOUBLE)' );

		$driver->query( 'INSERT INTO seeded_rand_values (id, value, other) VALUES (1, RAND(1), RAND(1)), (2, RAND(1), RAND(1))' );
		$rows = $driver->query( 'SELECT id, value, other FROM seeded_rand_values ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 2, $rows );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $rows[0]['other'], 1e-12 );
		$this->assertEqualsWithDelta( 0.14186032129625, (float) $rows[1]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.09445909605777, (float) $rows[1]['other'], 1e-12 );

		$driver->query( 'INSERT INTO seeded_rand_values (id, value, other) VALUES (3, RAND(1), RAND(NULL))' );
		$row = $driver->query( 'SELECT value, other FROM seeded_rand_values WHERE id = 3' )->fetch( PDO::FETCH_ASSOC );

		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.15522042769494, (float) $row['other'], 1e-12 );

		$driver->query( 'INSERT INTO seeded_rand_values (id, value, other) VALUES (4, RAND(1) + 0, 0 + RAND(1))' );
		$row = $driver->query( 'SELECT value, other FROM seeded_rand_values WHERE id = 4' )->fetch( PDO::FETCH_ASSOC );

		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $row['other'], 1e-12 );
	}

	public function test_insert_set_seeded_rand_literals_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_set (id INT, value DOUBLE, other DOUBLE)' );

		$driver->query( 'INSERT INTO seeded_rand_set SET id = 1, value = RAND(1), other = RAND(1)' );
		$driver->query( 'INSERT seeded_rand_set SET id = 2, value = RAND(NULL), other = RAND(1) + 0' );

		$rows = $driver->query( 'SELECT id, value, other FROM seeded_rand_set ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 2, $rows );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $rows[0]['other'], 1e-12 );
		$this->assertEqualsWithDelta( 0.15522042769494, (float) $rows[1]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[1]['other'], 1e-12 );
	}

	public function test_update_seeded_rand_literals_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_update (id INT, value DOUBLE, other DOUBLE)' );
		$driver->query( 'INSERT INTO seeded_rand_update (id, value, other) VALUES (1, 0.0, 0.0), (2, 0.0, 0.0), (3, 0.0, 0.0)' );

		$single = $driver->query( 'UPDATE seeded_rand_update SET value = RAND(1) WHERE id = 1' );
		$this->assertSame( 1, $single->rowCount() );
		$row = $driver->query( 'SELECT value FROM seeded_rand_update WHERE id = 1' )->fetch( PDO::FETCH_ASSOC );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['value'], 1e-12 );

		$ordered = $driver->query( 'UPDATE seeded_rand_update SET value = RAND(1), other = RAND(1) ORDER BY id LIMIT 2' );
		$this->assertSame( 2, $ordered->rowCount() );
		$rows = $driver->query( 'SELECT id, value, other FROM seeded_rand_update ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 3, $rows );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $rows[0]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.87161418038571, (float) $rows[0]['other'], 1e-12 );
		$this->assertEqualsWithDelta( 0.14186032129625, (float) $rows[1]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.09445909605777, (float) $rows[1]['other'], 1e-12 );
		$this->assertEqualsWithDelta( 0.0, (float) $rows[2]['value'], 1e-12 );
		$this->assertEqualsWithDelta( 0.0, (float) $rows[2]['other'], 1e-12 );

		$driver->query( 'UPDATE seeded_rand_update SET value = 0.0, other = 0.0' );
		$limited = $driver->query( 'UPDATE seeded_rand_update SET value = RAND(1) LIMIT 1' );
		$this->assertSame( 1, $limited->rowCount() );
		$row = $driver->query( 'SELECT COUNT(*) AS changed FROM seeded_rand_update WHERE abs(value - 0.40540353712198) < 0.000000000001' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 1, (int) $row['changed'] );
	}

	public function test_select_seeded_rand_unsupported_shapes_are_rejected(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t (id INT, value DOUBLE)' );
		$driver->query( 'INSERT INTO t (id, value) VALUES (1, 0.0)' );

		try {
			$driver->query( 'SELECT CAST(RAND(1) AS DOUBLE) AS r' );
			$this->fail( 'Expected nested seeded RAND() shape to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'top-level SELECT expression', $e->getMessage() );
		}

		$unsupported_contexts = array(
			array(
				'sql'     => 'SELECT RAND(1) AS r FROM t ORDER BY RAND(1)',
				'message' => 'top-level SELECT expression',
			),
		);

		foreach ( $unsupported_contexts as $case ) {
			try {
				$driver->query( $case['sql'] );
				$this->fail( 'Expected unsupported seeded RAND() context to fail: ' . $case['sql'] );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $case['message'], $e->getMessage(), $case['sql'] );
			}
		}

		try {
			$driver->query( 'INSERT INTO t (value) VALUES (RAND(CAST(1 AS SIGNED)))' );
			$this->fail( 'Expected unsupported non-literal INSERT VALUES seeded RAND() shape to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'literal numeric, string, or NULL seeds', $e->getMessage() );
		}

		try {
			$driver->query( 'INSERT INTO t SET value = RAND(CAST(1 AS SIGNED))' );
			$this->fail( 'Expected unsupported non-literal INSERT SET seeded RAND() shape to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'literal numeric, string, or NULL seeds', $e->getMessage() );
		}
	}

	public function test_update_seeded_rand_where_literal_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_where_update (id INT, value DOUBLE)' );
		$driver->query( 'INSERT INTO seeded_rand_where_update (id, value) VALUES (3, 0.0), (1, 0.0), (2, 0.0)' );

		$updated = $driver->query( 'UPDATE seeded_rand_where_update SET value = 9 WHERE RAND(1) < 0.5' );
		$this->assertSame( 2, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'value' => 0.0,
				),
				array(
					'id'    => 2,
					'value' => 9.0,
				),
				array(
					'id'    => 3,
					'value' => 9.0,
				),
			),
			$driver->query( 'SELECT id, value FROM seeded_rand_where_update ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$before = $driver->query( 'SELECT id, value FROM seeded_rand_where_update ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		try {
			$driver->query( 'UPDATE seeded_rand_where_update SET value = RAND(1) WHERE RAND(1) < 0.5' );
			$this->fail( 'Expected mixed seeded RAND() UPDATE context to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'cannot be used as both an UPDATE assignment and a WHERE predicate', $e->getMessage() );
		}

		$after = $driver->query( 'SELECT id, value FROM seeded_rand_where_update ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( $before, $after );

		$row = $driver->query( 'SELECT RAND(1) AS r' )->fetch( PDO::FETCH_ASSOC );
		$this->assertEqualsWithDelta( 0.40540353712198, (float) $row['r'], 1e-12 );
	}

	public function test_delete_seeded_rand_where_literal_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE seeded_rand_where_delete (id INT)' );
		$driver->query( 'INSERT INTO seeded_rand_where_delete (id) VALUES (3), (1), (2)' );

		$deleted = $driver->query( 'DELETE FROM seeded_rand_where_delete WHERE RAND(1) < 0.5' );

		$this->assertSame( 2, $deleted->rowCount() );
		$this->assertSame(
			array( 1 ),
			array_map(
				'intval',
				$driver->query( 'SELECT id FROM seeded_rand_where_delete ORDER BY id' )->fetchAll( PDO::FETCH_COLUMN )
			)
		);
	}

	public function test_meta_value_numeric_literal_predicates_coerce_strings(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE wptests_postmeta (post_id BIGINT(20), meta_key VARCHAR(255), meta_value LONGTEXT)' );
		$driver->query( 'CREATE TABLE wptests_commentmeta (comment_id BIGINT(20), meta_key VARCHAR(255), meta_value LONGTEXT)' );
		$driver->query(
			"INSERT INTO wptests_postmeta (post_id, meta_key, meta_value) VALUES
				(101, '_wp_trash_meta_time', '1780093651'),
				(102, '_wp_trash_meta_time', '1780093652'),
				(103, '_wp_trash_meta_time', '1780093653'),
				(104, '_wp_trash_meta_status', 'publish')"
		);
		$driver->query(
			"INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value) VALUES
				(201, '_wp_trash_meta_time', '1780093651'),
				(202, '_wp_trash_meta_time', '1780093652'),
				(203, '_wp_trash_meta_time', '1780093653'),
				(204, '_wp_trash_meta_status', 'approve')"
		);

		$cases = array(
			array(
				'sql'      => "SELECT post_id FROM wptests_postmeta
					WHERE meta_key = '_wp_trash_meta_time' AND meta_value < 1780093652
					ORDER BY post_id",
				'expected' => array( array( 'post_id' => 101 ) ),
				'cast'     => 'CAST("meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT wptests_postmeta.post_id FROM wptests_postmeta
					WHERE wptests_postmeta.meta_key = '_wp_trash_meta_time'
						AND wptests_postmeta.meta_value < 1780093652
					ORDER BY wptests_postmeta.post_id",
				'expected' => array( array( 'post_id' => 101 ) ),
				'cast'     => 'CAST("wptests_postmeta"."meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT pm.post_id FROM wptests_postmeta pm
					WHERE pm.meta_key = '_wp_trash_meta_time' AND pm.meta_value < 1780093652
					ORDER BY pm.post_id",
				'expected' => array( array( 'post_id' => 101 ) ),
				'cast'     => 'CAST("pm"."meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT comment_id FROM wptests_commentmeta
					WHERE meta_key = '_wp_trash_meta_time' AND meta_value < 1780093652
					ORDER BY comment_id",
				'expected' => array( array( 'comment_id' => 201 ) ),
				'cast'     => 'CAST("meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT wptests_commentmeta.comment_id FROM wptests_commentmeta
					WHERE wptests_commentmeta.meta_key = '_wp_trash_meta_time'
						AND wptests_commentmeta.meta_value < 1780093652
					ORDER BY wptests_commentmeta.comment_id",
				'expected' => array( array( 'comment_id' => 201 ) ),
				'cast'     => 'CAST("wptests_commentmeta"."meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT cm.comment_id FROM wptests_commentmeta cm
					WHERE cm.meta_key = '_wp_trash_meta_time' AND cm.meta_value < 1780093652
					ORDER BY cm.comment_id",
				'expected' => array( array( 'comment_id' => 201 ) ),
				'cast'     => 'CAST("cm"."meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR)',
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				$driver->query( $case['sql'] )->fetchAll( PDO::FETCH_ASSOC ),
				$case['sql']
			);
			$this->assertStringContainsString( $case['cast'], $this->lastDuckDBQuery( $driver ), $case['sql'] );
		}
	}

	public function test_text_value_numeric_literal_predicates_use_sqlite_text_affinity(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE wptests_postmeta (meta_id BIGINT(20), meta_value LONGTEXT)' );
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, meta_value) VALUES
				(1, '10'),
				(2, '010'),
				(3, '10abc'),
				(4, ' 11x'),
				(5, 'abc'),
				(6, ''),
				(7, '-7'),
				(8, '10.50'),
				(9, NULL)"
		);
		$driver->query( 'CREATE TABLE wptests_options (option_id BIGINT(20), option_value LONGTEXT)' );
		$driver->query(
			"INSERT INTO wptests_options (option_id, option_value) VALUES
				(1, '10'),
				(2, '010'),
				(3, '10abc'),
				(4, ' 11x'),
				(5, 'abc')"
		);

		$cases = array(
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value = 10 ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) = CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value = 10.5 ORDER BY meta_id',
				'expected' => array(),
				'fragment' => 'CAST("meta_value" AS VARCHAR) = \'10.5\'',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 10 = meta_value ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST(10 AS VARCHAR) = CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 10.5 > meta_value ORDER BY meta_id',
				'expected' => array( 1, 2, 4, 6, 7 ),
				'fragment' => '\'10.5\' > CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value = -7 ORDER BY meta_id',
				'expected' => array( 7 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) = CAST(-7 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value <> 10 ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) <> CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 10 <> meta_value ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST(10 AS VARCHAR) <> CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value != 10 ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) != CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value < 11 ORDER BY meta_id',
				'expected' => array( 1, 2, 3, 4, 6, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) < CAST(11 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value < 10.5 ORDER BY meta_id',
				'expected' => array( 1, 2, 4, 6, 7 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) < \'10.5\'',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 11 > meta_value ORDER BY meta_id',
				'expected' => array( 1, 2, 3, 4, 6, 7, 8 ),
				'fragment' => 'CAST(11 AS VARCHAR) > CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value BETWEEN 10 AND 11 ORDER BY meta_id',
				'expected' => array( 1, 3, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND CAST(11 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value BETWEEN 10 AND 'abc' ORDER BY meta_id",
				'expected' => array( 1, 3, 5, 8 ),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND 'abc'",
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value BETWEEN '10' AND 11 ORDER BY meta_id",
				'expected' => array( 1, 3, 8 ),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) BETWEEN '10' AND CAST(11 AS VARCHAR)",
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value BETWEEN 10.0 AND 10.9 ORDER BY meta_id',
				'expected' => array( 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) BETWEEN \'10.0\' AND \'10.9\'',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value BETWEEN -7 AND +7 ORDER BY meta_id',
				'expected' => array( 1, 2, 3, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) BETWEEN CAST(-7 AS VARCHAR) AND \'7\'',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value NOT BETWEEN 10 AND 11 ORDER BY meta_id',
				'expected' => array( 2, 4, 5, 6, 7 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND CAST(11 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value NOT BETWEEN 10 AND 'abc' ORDER BY meta_id",
				'expected' => array( 2, 4, 6, 7 ),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND 'abc'",
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (10, 11) ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) IN (CAST(10 AS VARCHAR), CAST(11 AS VARCHAR))',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (10.5, 11.5) ORDER BY meta_id',
				'expected' => array(),
				'fragment' => 'CAST("meta_value" AS VARCHAR) IN (\'10.5\', \'11.5\')',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (-7, +7) ORDER BY meta_id',
				'expected' => array( 7 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) IN (CAST(-7 AS VARCHAR), \'7\')',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (10, NULL) ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) IN (CAST(10 AS VARCHAR), NULL)',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (10, 'abc') ORDER BY meta_id",
				'expected' => array( 1, 5 ),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) IN (CAST(10 AS VARCHAR), 'abc')",
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value IN (10, NULL, 'abc', 10.5) ORDER BY meta_id",
				'expected' => array( 1, 5 ),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) IN (CAST(10 AS VARCHAR), NULL, 'abc', '10.5')",
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value NOT IN (10, 11) ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), CAST(11 AS VARCHAR))',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE meta_value NOT IN (10, NULL, 'abc') ORDER BY meta_id",
				'expected' => array(),
				'fragment' => "CAST(\"meta_value\" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), NULL, 'abc')",
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value LIKE 10 ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) LIKE CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE meta_value NOT LIKE 10 ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST("meta_value" AS VARCHAR) NOT LIKE CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 10 LIKE meta_value ORDER BY meta_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST(10 AS VARCHAR) LIKE CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT meta_id FROM wptests_postmeta WHERE 10 NOT LIKE meta_value ORDER BY meta_id',
				'expected' => array( 2, 3, 4, 5, 6, 7, 8 ),
				'fragment' => 'CAST(10 AS VARCHAR) NOT LIKE CAST("meta_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value < 11 ORDER BY option_id',
				'expected' => array( 1, 2, 3, 4 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) < CAST(11 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value < 10.5 ORDER BY option_id',
				'expected' => array( 1, 2, 4 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) < \'10.5\'',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value <> 10 ORDER BY option_id',
				'expected' => array( 2, 3, 4, 5 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) <> CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value NOT BETWEEN 10 AND 11 ORDER BY option_id',
				'expected' => array( 2, 4, 5 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND CAST(11 AS VARCHAR)',
			),
			array(
				'sql'      => "SELECT option_id FROM wptests_options WHERE option_value BETWEEN 10 AND 'abc' ORDER BY option_id",
				'expected' => array( 1, 3, 5 ),
				'fragment' => "CAST(\"option_value\" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND 'abc'",
			),
			array(
				'sql'      => "SELECT option_id FROM wptests_options WHERE option_value NOT BETWEEN 10 AND 'abc' ORDER BY option_id",
				'expected' => array( 2, 4 ),
				'fragment' => "CAST(\"option_value\" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND 'abc'",
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value NOT IN (10, 11) ORDER BY option_id',
				'expected' => array( 2, 3, 4, 5 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), CAST(11 AS VARCHAR))',
			),
			array(
				'sql'      => "SELECT option_id FROM wptests_options WHERE option_value IN (10, NULL, '10abc') ORDER BY option_id",
				'expected' => array( 1, 3 ),
				'fragment' => "CAST(\"option_value\" AS VARCHAR) IN (CAST(10 AS VARCHAR), NULL, '10abc')",
			),
			array(
				'sql'      => "SELECT option_id FROM wptests_options WHERE option_value NOT IN (10, NULL, '10abc') ORDER BY option_id",
				'expected' => array(),
				'fragment' => "CAST(\"option_value\" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), NULL, '10abc')",
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value LIKE 10 ORDER BY option_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) LIKE CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE option_value NOT LIKE 10 ORDER BY option_id',
				'expected' => array( 2, 3, 4, 5 ),
				'fragment' => 'CAST("option_value" AS VARCHAR) NOT LIKE CAST(10 AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE 10 LIKE option_value ORDER BY option_id',
				'expected' => array( 1 ),
				'fragment' => 'CAST(10 AS VARCHAR) LIKE CAST("option_value" AS VARCHAR)',
			),
			array(
				'sql'      => 'SELECT option_id FROM wptests_options WHERE 10 NOT LIKE option_value ORDER BY option_id',
				'expected' => array( 2, 3, 4, 5 ),
				'fragment' => 'CAST(10 AS VARCHAR) NOT LIKE CAST("option_value" AS VARCHAR)',
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				array_map(
					'intval',
					$driver->query( $case['sql'] )->fetchAll( PDO::FETCH_COLUMN )
				),
				$case['sql']
			);
			$this->assertStringContainsString( $case['fragment'], $this->lastDuckDBQuery( $driver ), $case['sql'] );
		}
	}

	public function test_sql_calc_found_rows_numeric_string_and_text_value_arithmetic_coercions(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wptests_users (
				ID BIGINT(20) UNSIGNED NOT NULL,
				user_login VARCHAR(60) NOT NULL,
				PRIMARY KEY (ID)
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_usermeta (
				umeta_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(255),
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id)
			)'
		);
		$driver->query( "INSERT INTO wptests_users (ID, user_login) VALUES (0, 'zero'), (1, 'one'), (2, 'two')" );
		$driver->query(
			"INSERT INTO wptests_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES
				(10, 0, 'user_age', 'abc'),
				(11, 1, 'user_age', '10'),
				(12, 2, 'user_age', '2'),
				(13, 1, 'empty_age', NULL),
				(14, 1, 'numeric_prefix', '10abc'),
				(15, 1, 'numeric_prefix', ' 11x'),
				(16, 1, 'numeric_prefix', '-2.5z'),
				(17, 1, 'numeric_prefix', '+3e2tail'),
				(18, 1, 'numeric_prefix', '.75q'),
				(19, 1, 'numeric_prefix', 'abc'),
				(20, 1, 'numeric_prefix', ''),
				(21, 1, 'numeric_prefix', '0x10'),
				(22, 1, 'numeric_prefix', NULL)"
		);
		$driver->query( 'CREATE TABLE wptests_nullable_ids (ID BIGINT(20), label VARCHAR(20))' );
		$driver->query( "INSERT INTO wptests_nullable_ids (ID, label) VALUES (NULL, 'null'), (1, 'one')" );
		$driver->query( 'CREATE TABLE plugin_items (id VARCHAR(20), parent VARCHAR(20), count VARCHAR(20))' );
		$driver->query( "INSERT INTO plugin_items (id, parent, count) VALUES ('2', 'abc', '5'), ('abc', 'def', '9')" );

		$string_search         = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wptests_users
			WHERE ID = 'yololololo' OR user_login LIKE '%yololololo%'
			ORDER BY ID
			LIMIT 0, 10"
		)->fetchAll( PDO::FETCH_ASSOC );
		$string_search_queries = $driver->get_last_duckdb_queries();

		$this->assertSame( array(), $string_search );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString(
			"CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('yololololo' AS DOUBLE) END",
			implode( "\n", $string_search_queries )
		);

		$numeric_string_search = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wptests_users
			WHERE ID = '02'
			ORDER BY ID"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( array( 'ID' => 2 ) ), $numeric_string_search );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$meta_sort         = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_users.ID
			FROM wptests_users INNER JOIN wptests_usermeta ON ( wptests_users.ID = wptests_usermeta.user_id )
			WHERE wptests_usermeta.meta_key = 'user_age'
			ORDER BY wptests_usermeta.meta_value+0 ASC
			LIMIT 0, 2"
		)->fetchAll( PDO::FETCH_ASSOC );
		$meta_sort_queries = $driver->get_last_duckdb_queries();

		$this->assertSame(
			array(
				array( 'ID' => 0 ),
				array( 'ID' => 2 ),
			),
			$meta_sort
		);
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$meta_value_numeric_prefix_sql             = 'CASE WHEN "wptests_usermeta"."meta_value" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST("wptests_usermeta"."meta_value" AS VARCHAR), \'^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?\', 0), \'\') AS DOUBLE), 0) END';
		$unqualified_meta_value_numeric_prefix_sql = 'CASE WHEN "meta_value" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST("meta_value" AS VARCHAR), \'^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?\', 0), \'\') AS DOUBLE), 0) END';
		$this->assertStringContainsString( $meta_value_numeric_prefix_sql . ' + 0', implode( "\n", $meta_sort_queries ) );

		$this->assertSame(
			array( array( 'coerced' => null ) ),
			$driver->query(
				'SELECT wptests_usermeta.meta_value + 0 AS coerced
				FROM wptests_usermeta
				WHERE umeta_id = 13'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'umeta_id' => 14,
					'coerced'  => 10.0,
				),
				array(
					'umeta_id' => 15,
					'coerced'  => 11.0,
				),
				array(
					'umeta_id' => 16,
					'coerced'  => -2.5,
				),
				array(
					'umeta_id' => 17,
					'coerced'  => 300.0,
				),
				array(
					'umeta_id' => 18,
					'coerced'  => 0.75,
				),
				array(
					'umeta_id' => 19,
					'coerced'  => 0.0,
				),
				array(
					'umeta_id' => 20,
					'coerced'  => 0.0,
				),
				array(
					'umeta_id' => 21,
					'coerced'  => 0.0,
				),
				array(
					'umeta_id' => 22,
					'coerced'  => null,
				),
			),
			$driver->query(
				"SELECT umeta_id, meta_value + 0 AS coerced
				FROM wptests_usermeta
				WHERE meta_key = 'numeric_prefix'
				ORDER BY umeta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array( 'umeta_id' => 16 ),
				array( 'umeta_id' => 19 ),
				array( 'umeta_id' => 20 ),
				array( 'umeta_id' => 21 ),
				array( 'umeta_id' => 18 ),
				array( 'umeta_id' => 14 ),
				array( 'umeta_id' => 15 ),
				array( 'umeta_id' => 17 ),
			),
			$driver->query(
				"SELECT umeta_id
				FROM wptests_usermeta
				WHERE meta_key = 'numeric_prefix' AND meta_value IS NOT NULL
				ORDER BY meta_value + 0 ASC, umeta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array( 'umeta_id' => 22 ),
				array( 'umeta_id' => 16 ),
				array( 'umeta_id' => 19 ),
				array( 'umeta_id' => 20 ),
				array( 'umeta_id' => 21 ),
				array( 'umeta_id' => 18 ),
				array( 'umeta_id' => 14 ),
				array( 'umeta_id' => 15 ),
				array( 'umeta_id' => 17 ),
			),
			$driver->query(
				"SELECT umeta_id
				FROM wptests_usermeta
				WHERE meta_key = 'numeric_prefix'
				ORDER BY meta_value + 0 ASC, umeta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( $unqualified_meta_value_numeric_prefix_sql . ' + 0 ASC NULLS FIRST', $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array(
				array( 'umeta_id' => 22 ),
				array( 'umeta_id' => 16 ),
				array( 'umeta_id' => 19 ),
				array( 'umeta_id' => 20 ),
				array( 'umeta_id' => 21 ),
				array( 'umeta_id' => 18 ),
				array( 'umeta_id' => 14 ),
				array( 'umeta_id' => 15 ),
				array( 'umeta_id' => 17 ),
			),
			$driver->query(
				"SELECT umeta_id
				FROM wptests_usermeta
				WHERE meta_key = 'numeric_prefix'
				ORDER BY meta_value + 0, umeta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( $unqualified_meta_value_numeric_prefix_sql . ' + 0 NULLS FIRST', $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array(
				array( 'umeta_id' => 17 ),
				array( 'umeta_id' => 15 ),
				array( 'umeta_id' => 14 ),
				array( 'umeta_id' => 18 ),
				array( 'umeta_id' => 19 ),
				array( 'umeta_id' => 20 ),
				array( 'umeta_id' => 21 ),
				array( 'umeta_id' => 16 ),
				array( 'umeta_id' => 22 ),
			),
			$driver->query(
				"SELECT umeta_id
				FROM wptests_usermeta
				WHERE meta_key = 'numeric_prefix'
				ORDER BY meta_value + 0 DESC, umeta_id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( $unqualified_meta_value_numeric_prefix_sql . ' + 0 DESC', $this->lastDuckDBQuery( $driver ) );
		$this->assertStringNotContainsString( 'DESC NULLS FIRST', $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array( array( 'label' => 'one' ) ),
			$driver->query( "SELECT label FROM wptests_nullable_ids WHERE ID != 'abc' ORDER BY label" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(),
			$driver->query( "SELECT id FROM plugin_items WHERE id < '10' ORDER BY id" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringNotContainsString( 'TRY_CAST', $this->lastDuckDBQuery( $driver ) );

		$this->assertSame(
			array(),
			$driver->query( "SELECT plugin_items.count FROM plugin_items WHERE plugin_items.count < '10' ORDER BY plugin_items.count" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringNotContainsString( 'TRY_CAST', $this->lastDuckDBQuery( $driver ) );
	}

	public function test_numeric_identifier_string_literal_between_predicates_match_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				post_title VARCHAR(200) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_parent, post_title) VALUES
				(1, 0, 'one'),
				(2, 1, 'two'),
				(3, 1, 'three'),
				(10, 2, 'ten')"
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(255),
				PRIMARY KEY (meta_id)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key) VALUES
				(1, 1, 'a'),
				(2, 3, 'b'),
				(3, 10, 'c')"
		);

		$cases = array(
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID BETWEEN '1' AND 'yololololo' ORDER BY ID",
				'expected' => array( 1, 2, 3, 10 ),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN TRUE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT BETWEEN '2' AND 'yololololo' ORDER BY ID",
				'expected' => array( 1 ),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID BETWEEN 'yololololo' AND '3' ORDER BY ID",
				'expected' => array(),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE",
			),
			array(
				'sql'      => "SELECT p.ID FROM wptests_posts AS p WHERE p.ID BETWEEN '1' AND 'yololololo' ORDER BY p.ID",
				'expected' => array( 1, 2, 3, 10 ),
				'fragment' => '"p"."ID" >= TRY_CAST(\'1\' AS DOUBLE)',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE post_parent BETWEEN '1' AND 'yololololo' ORDER BY ID",
				'expected' => array( 2, 3, 10 ),
				'fragment' => '"post_parent" >= TRY_CAST(\'1\' AS DOUBLE)',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE post_id BETWEEN '1' AND 'yololololo' ORDER BY meta_id",
				'expected' => array( 1, 2, 3 ),
				'fragment' => '"post_id" <= TRY_CAST(\'yololololo\' AS DOUBLE)',
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				array_map(
					'intval',
					$driver->query( $case['sql'] )->fetchAll( PDO::FETCH_COLUMN )
				),
				$case['sql']
			);
			$this->assertStringContainsString( $case['fragment'], $this->lastDuckDBQuery( $driver ), $case['sql'] );
		}

		$result = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wptests_posts
			WHERE ID BETWEEN '1' AND 'yololololo'
			ORDER BY ID
			LIMIT 10"
		);
		$this->assertSame(
			array(
				array( 'ID' => 1 ),
				array( 'ID' => 2 ),
				array( 'ID' => 3 ),
				array( 'ID' => 10 ),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 4 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_numeric_identifier_like_predicates_match_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				post_title VARCHAR(200) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_parent, post_title) VALUES
				(1, 0, 'one'),
				(2, 1, 'two'),
				(10, 1, 'ten'),
				(21, 2, 'twenty-one')"
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(255),
				PRIMARY KEY (meta_id)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key) VALUES
				(1, 1, 'a'),
				(2, 2, 'b'),
				(10, 10, 'c'),
				(21, 21, 'd')"
		);

		$cases = array(
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID LIKE '1%' ORDER BY ID",
				'expected' => array( 1, 10 ),
				'fragment' => 'CAST("ID" AS VARCHAR) LIKE \'1%\'',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT LIKE '1%' ORDER BY ID",
				'expected' => array( 2, 21 ),
				'fragment' => 'CAST("ID" AS VARCHAR) NOT LIKE \'1%\'',
			),
			array(
				'sql'      => "SELECT p.ID FROM wptests_posts AS p WHERE p.ID LIKE '1%' ORDER BY p.ID",
				'expected' => array( 1, 10 ),
				'fragment' => 'CAST("p"."ID" AS VARCHAR) LIKE \'1%\'',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE post_parent LIKE '1%' ORDER BY ID",
				'expected' => array( 2, 10 ),
				'fragment' => 'CAST("post_parent" AS VARCHAR) LIKE \'1%\'',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE post_id LIKE '1%' ORDER BY meta_id",
				'expected' => array( 1, 10 ),
				'fragment' => 'CAST("post_id" AS VARCHAR) LIKE \'1%\'',
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				array_map(
					'intval',
					$driver->query( $case['sql'] )->fetchAll( PDO::FETCH_COLUMN )
				),
				$case['sql']
			);
			$this->assertStringContainsString( $case['fragment'], $this->lastDuckDBQuery( $driver ), $case['sql'] );
		}

		$result = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wptests_posts
			WHERE ID LIKE '1%'
			ORDER BY ID
			LIMIT 10"
		);
		$this->assertSame(
			array(
				array( 'ID' => 1 ),
				array( 'ID' => 10 ),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_numeric_identifier_string_literal_in_predicates_match_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				post_title VARCHAR(200) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_parent, post_title) VALUES
				(1, 0, 'one'),
				(2, 1, 'two'),
				(3, 1, 'three'),
				(10, 2, 'ten')"
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(255),
				PRIMARY KEY (meta_id)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key) VALUES
				(1, 1, 'a'),
				(2, 3, 'b'),
				(3, 10, 'c')"
		);

		$cases = array(
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID IN ('1', 'yololololo', '003') ORDER BY ID",
				'expected' => array( 1, 3 ),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID IN (1, 'yololololo', 3) ORDER BY ID",
				'expected' => array( 1, 3 ),
				'fragment' => "\"ID\" = 1 OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID IN (1, NULL, 'yololololo', 3) ORDER BY ID",
				'expected' => array( 1, 3 ),
				'fragment' => '"ID" = 1 OR NULL OR CASE WHEN "ID" IS NULL THEN NULL',
			),
			array(
				'sql'      => "SELECT p.ID FROM wptests_posts AS p WHERE p.ID IN ('1', 'yololololo', '003') ORDER BY p.ID",
				'expected' => array( 1, 3 ),
				'fragment' => '"p"."ID" = TRY_CAST(\'003\' AS DOUBLE)',
			),
			array(
				'sql'      => "SELECT p.ID FROM wptests_posts AS p WHERE p.ID IN (1, 'yololololo', 3) ORDER BY p.ID",
				'expected' => array( 1, 3 ),
				'fragment' => '"p"."ID" = 1 OR CASE WHEN "p"."ID" IS NULL THEN NULL',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE post_parent IN ('1', 'yololololo') ORDER BY ID",
				'expected' => array( 2, 3 ),
				'fragment' => '"post_parent" = TRY_CAST(\'1\' AS DOUBLE)',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE post_parent IN (1, 'yololololo') ORDER BY ID",
				'expected' => array( 2, 3 ),
				'fragment' => '"post_parent" = 1 OR CASE WHEN "post_parent" IS NULL THEN NULL',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE post_id IN ('1', 'yololololo', '003') ORDER BY meta_id",
				'expected' => array( 1, 2 ),
				'fragment' => '"post_id" = TRY_CAST(\'003\' AS DOUBLE)',
			),
			array(
				'sql'      => "SELECT meta_id FROM wptests_postmeta WHERE post_id IN (1, 'yololololo', 3) ORDER BY meta_id",
				'expected' => array( 1, 2 ),
				'fragment' => '"post_id" = 1 OR CASE WHEN "post_id" IS NULL THEN NULL',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID IN ('', '2') ORDER BY ID",
				'expected' => array( 2 ),
				'fragment' => "TRY_CAST('' AS DOUBLE) IS NULL THEN FALSE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT IN ('1', 'yololololo', '003') ORDER BY ID",
				'expected' => array( 2, 10 ),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN TRUE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT IN (2, 'yololololo') ORDER BY ID",
				'expected' => array( 1, 3, 10 ),
				'fragment' => "\"ID\" <> 2 AND CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN TRUE",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT IN (2, NULL, 'yololololo') ORDER BY ID",
				'expected' => array(),
				'fragment' => '"ID" <> 2 AND NULL AND CASE WHEN "ID" IS NULL THEN NULL',
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID IN ('yololololo', NULL) ORDER BY ID",
				'expected' => array(),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('yololololo' AS DOUBLE) END OR NULL",
			),
			array(
				'sql'      => "SELECT ID FROM wptests_posts WHERE ID NOT IN ('yololololo', NULL) ORDER BY ID",
				'expected' => array(),
				'fragment' => "TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN TRUE ELSE \"ID\" <> TRY_CAST('yololololo' AS DOUBLE) END AND NULL",
			),
		);

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				array_map(
					'intval',
					$driver->query( $case['sql'] )->fetchAll( PDO::FETCH_COLUMN )
				),
				$case['sql']
			);
			$this->assertStringContainsString( $case['fragment'], $this->lastDuckDBQuery( $driver ), $case['sql'] );
		}

		$result = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wptests_posts
			WHERE ID IN (1, NULL, 'yololololo', 3)
			ORDER BY ID
			LIMIT 10"
		);
		$this->assertSame(
			array(
				array( 'ID' => 1 ),
				array( 'ID' => 3 ),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_date_format_function_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$row    = $driver->query( "SELECT DATE_FORMAT(DATE '2026-06-26', '%Y-%m-%d') AS formatted_date, DATE_FORMAT('2026-06-26 14:15:16', '%Y-%m-%d %H:%i:%s') AS formatted_datetime, DATE_FORMAT('2026-06-26 14:15:16', '%M %W %h:%i %p') AS formatted_names, DATE_FORMAT('2026-06-26 14:15:16', '%H.%i') AS hm" )->fetch( PDO::FETCH_ASSOC );

		$this->assertSame( '2026-06-26', $row['formatted_date'] );
		$this->assertSame( '2026-06-26 14:15:16', $row['formatted_datetime'] );
		$this->assertSame( 'June Friday 02:15 PM', $row['formatted_names'] );
		$this->assertEqualsWithDelta( 14.15, (float) $row['hm'], 0.000001 );
	}

	public function test_date_part_functions_translate_wordpress_datetime_strings_with_try_cast(): void {
		$connection = new class() extends WP_DuckDB_Connection {
			public function __construct() {}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				if ( 0 === strpos( $sql, 'CREATE OR REPLACE MACRO ' ) ) {
					return new WP_DuckDB_Result_Statement( array(), array(), 0 );
				}

				throw new RuntimeException( 'Unexpected query: ' . $sql );
			}
		};
		$driver     = new WP_DuckDB_Driver( array( 'connection' => $connection ) );
		$tokenize   = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$translate  = new ReflectionMethod( WP_DuckDB_Driver::class, 'translate_tokens_to_duckdb_sql' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$translate->setAccessible( true );
		}

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month, DAYOFMONTH( post_date ) AS day
			FROM wp_posts
			WHERE post_type = 'attachment'
			ORDER BY post_date DESC"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( 'year(TRY_CAST((post_date) AS TIMESTAMP)) AS year', $sql );
		$this->assertStringContainsString( 'month(TRY_CAST((post_date) AS TIMESTAMP)) AS month', $sql );
		$this->assertStringContainsString( 'dayofmonth(TRY_CAST((post_date) AS TIMESTAMP)) AS day', $sql );

		$tokens = $tokenize->invoke(
			$driver,
			'SELECT DATE(post_date) AS post_day, DATEDIFF(post_modified, post_date) AS days_old, MONTHNUM(post_date) AS monthnum
			FROM wp_posts'
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( "strftime(TRY_CAST((post_date) AS TIMESTAMP), '%Y-%m-%d') AS post_day", $sql );
		$this->assertStringContainsString( 'WHEN CAST((post_modified) AS VARCHAR) IN (\'0000-00-00\', \'0000-00-00 00:00:00\') THEN NULL', $sql );
		$this->assertStringContainsString( 'WHEN TRY_CAST((post_modified) AS DATE) IS NULL THEN error(\'Failed to parse time string\')', $sql );
		$this->assertStringContainsString( 'WHEN CAST((post_date) AS VARCHAR) IN (\'0000-00-00\', \'0000-00-00 00:00:00\') THEN NULL', $sql );
		$this->assertStringContainsString( 'WHEN TRY_CAST((post_date) AS DATE) IS NULL THEN error(\'Failed to parse time string\')', $sql );
		$this->assertStringContainsString( 'month(TRY_CAST((post_date) AS TIMESTAMP)) AS monthnum', $sql );

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT ID FROM wp_posts
			WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') = 20160116
				OR 20160116 < DATE_FORMAT(post_date_gmt, '%Y%m%d')
				OR DATE_FORMAT(post_date_gmt, '%H.%i') >= 12.00"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( "CASE WHEN strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%Y%m%d') IS NULL THEN NULL ELSE FALSE END", $sql );
		$this->assertStringContainsString( "CASE WHEN strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%Y%m%d') IS NULL THEN NULL ELSE TRUE END", $sql );
		$this->assertStringContainsString( "CAST(strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%H.%M') AS DOUBLE) >= 12.00", $sql );

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT ID FROM wp_posts
			WHERE DATE(post_date_gmt) = 20160116
				OR 20160116 < DATE(post_date_gmt)
				OR DATE(post_date_gmt) = '2016-01-16'"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( "CASE WHEN strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%Y-%m-%d') IS NULL THEN NULL ELSE FALSE END", $sql );
		$this->assertStringContainsString( "CASE WHEN strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%Y-%m-%d') IS NULL THEN NULL ELSE TRUE END", $sql );
		$this->assertStringContainsString( "strftime(TRY_CAST((post_date_gmt) AS TIMESTAMP), '%Y-%m-%d') = '2016-01-16'", $sql );

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT ID FROM wp_posts
			WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) = 20160117
				OR 20160117 < DATE_SUB(post_date_gmt, INTERVAL 1 DAY)
				OR DATE_ADD(post_date_gmt, INTERVAL 1 DAY) = '2016-01-17 00:00:00'"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( "CASE WHEN strftime(CAST((post_date_gmt) AS TIMESTAMP) + CAST((1) AS BIGINT) * INTERVAL 1 DAY, '%Y-%m-%d %H:%M:%S') IS NULL THEN NULL ELSE FALSE END", $sql );
		$this->assertStringContainsString( "CASE WHEN strftime(CAST((post_date_gmt) AS TIMESTAMP) - CAST((1) AS BIGINT) * INTERVAL 1 DAY, '%Y-%m-%d %H:%M:%S') IS NULL THEN NULL ELSE TRUE END", $sql );
		$this->assertStringContainsString( "strftime(CAST((post_date_gmt) AS TIMESTAMP) + CAST((1) AS BIGINT) * INTERVAL 1 DAY, '%Y-%m-%d %H:%M:%S') = '2016-01-17 00:00:00'", $sql );

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT HOUR(post_date) AS hour, MINUTE(post_date) AS minute, SECOND(post_date) AS second,
				DAYOFWEEK(post_date) AS day_of_week, WEEKDAY(post_date) AS weekday,
				WEEK(post_date, 1) AS week, DATE_FORMAT(post_date, '%Y-%m-%d %H:%i:%s') AS formatted,
				DATE_FORMAT(post_date, '%H.%i') AS hm
			FROM wp_posts"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( 'hour(TRY_CAST((post_date) AS TIME)) AS hour', $sql );
		$this->assertStringContainsString( 'minute(TRY_CAST((post_date) AS TIME)) AS minute', $sql );
		$this->assertStringContainsString( 'second(TRY_CAST((post_date) AS TIME)) AS second', $sql );
		$this->assertStringContainsString( '(dayofweek(TRY_CAST((post_date) AS DATE)) + 1) AS day_of_week', $sql );
		$this->assertStringContainsString( '((dayofweek(TRY_CAST((post_date) AS DATE)) + 6) % 7) AS weekday', $sql );
		$this->assertStringContainsString( 'week(TRY_CAST((post_date) AS DATE)) AS week', $sql );
		$this->assertStringContainsString( "strftime(TRY_CAST((post_date) AS TIMESTAMP), '%Y-%m-%d %H:%M:%S') AS formatted", $sql );
		$this->assertStringContainsString( "CAST(strftime(TRY_CAST((post_date) AS TIMESTAMP), '%H.%M') AS DOUBLE) AS hm", $sql );

		$tokens = $tokenize->invoke(
			$driver,
			"SELECT ID FROM wp_posts
			WHERE YEAR(post_date_gmt) = '2016'
				OR YEAR(post_date_gmt) != '2016'
				OR '2016' > YEAR(post_date_gmt)
				OR YEAR(post_date_gmt) = 2016"
		);
		$sql    = $translate->invoke( $driver, $tokens );

		$this->assertStringContainsString( 'CASE WHEN year(TRY_CAST((post_date_gmt) AS TIMESTAMP)) IS NULL THEN FALSE ELSE FALSE END', $sql );
		$this->assertStringContainsString( 'CASE WHEN year(TRY_CAST((post_date_gmt) AS TIMESTAMP)) IS NULL THEN TRUE ELSE TRUE END', $sql );
		$this->assertStringContainsString( 'year(TRY_CAST((post_date_gmt) AS TIMESTAMP)) = 2016', $sql );
	}

	public function test_rest_iso_datetime_literal_comparisons_cast_known_datetime_columns(): void {
		$driver    = $this->new_byte_safe_duckdb_driver();
		$tokenize  = new ReflectionMethod( WP_DuckDB_Driver::class, 'tokenize_and_validate' );
		$translate = new ReflectionMethod( WP_DuckDB_Driver::class, 'translate_tokens_to_duckdb_sql' );
		if ( PHP_VERSION_ID < 80100 ) {
			$tokenize->setAccessible( true );
			$translate->setAccessible( true );
		}

		$sql = $translate->invoke(
			$driver,
			$tokenize->invoke(
				$driver,
				"SELECT ID FROM wp_posts
				WHERE post_date_gmt >= '2020-01-02T00:00:00Z'
					AND post_date_gmt <= '2020-01-02T23:59:59Z'"
			)
		);
		$this->assertStringContainsString( 'TRY_CAST("post_date_gmt" AS TIMESTAMP) >= TRY_CAST(\'2020-01-02T00:00:00Z\' AS TIMESTAMP)', $sql );
		$this->assertStringContainsString( 'TRY_CAST("post_date_gmt" AS TIMESTAMP) <= TRY_CAST(\'2020-01-02T23:59:59Z\' AS TIMESTAMP)', $sql );

		$sql = $translate->invoke(
			$driver,
			$tokenize->invoke(
				$driver,
				"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts
				WHERE 1=1
					AND ( wp_posts.post_date_gmt >= '2020-01-02T00:00:00Z'
						AND wp_posts.post_date_gmt <= '2020-01-02T23:59:59Z' )
				ORDER BY wp_posts.post_date DESC
				LIMIT 0, 10"
			)
		);
		$this->assertStringContainsString( 'TRY_CAST("wp_posts"."post_date_gmt" AS TIMESTAMP) >= TRY_CAST(\'2020-01-02T00:00:00Z\' AS TIMESTAMP)', $sql );
		$this->assertStringContainsString( 'TRY_CAST("wp_posts"."post_date_gmt" AS TIMESTAMP) <= TRY_CAST(\'2020-01-02T23:59:59Z\' AS TIMESTAMP)', $sql );

		$sql = $translate->invoke(
			$driver,
			$tokenize->invoke(
				$driver,
				"SELECT p.ID FROM wp_posts AS p
				WHERE p.post_modified_gmt <= '2020-01-02T23:59:59Z'"
			)
		);
		$this->assertStringContainsString( 'TRY_CAST("p"."post_modified_gmt" AS TIMESTAMP) <= TRY_CAST(\'2020-01-02T23:59:59Z\' AS TIMESTAMP)', $sql );

		$sql = $translate->invoke(
			$driver,
			$tokenize->invoke(
				$driver,
				"SELECT comment_ID FROM wp_comments
				WHERE '2020-01-02T00:00:00Z' <= comment_date_gmt"
			)
		);
		$this->assertStringContainsString( 'TRY_CAST(\'2020-01-02T00:00:00Z\' AS TIMESTAMP) <= TRY_CAST("comment_date_gmt" AS TIMESTAMP)', $sql );

		foreach (
			array(
				"SELECT ID FROM wp_posts WHERE post_date_gmt >= '2020-01-02 00:00:00'",
				"SELECT ID FROM wp_posts WHERE post_title >= '2020-01-02T00:00:00Z'",
				"SELECT ID FROM plugin_events WHERE created_at >= '2020-01-02T00:00:00Z'",
				"SELECT id FROM plugin_events WHERE post_date_gmt >= '2020-01-02T00:00:00Z'",
				"SELECT ID FROM wp_posts WHERE post_type = '2020-01-02T00:00:00Z'",
				"SELECT ID FROM wp_posts WHERE post_date_gmt != '2020-01-02T00:00:00Z'",
				"SELECT ID FROM wp_posts WHERE post_date_gmt >= 'not-a-dateT00:00:00Z'",
				"SELECT ID FROM wp_posts WHERE post_date_gmt LIKE '2020-01-02T%'",
				"SELECT ID FROM wp_posts WHERE DATE(post_date_gmt) >= '2020-01-02T00:00:00Z'",
				"SELECT ID FROM wp_posts WHERE post_date_gmt >= '2020-01-02T00:00:00Z' + INTERVAL 1 DAY",
				"SELECT ID FROM wp_posts WHERE ID IN (SELECT id FROM plugin_events WHERE post_date_gmt >= '2020-01-02T00:00:00Z')",
				"SELECT ID FROM wp_posts WHERE post_date_gmt >= '2020-01-02T00:00:00Z' UNION SELECT id FROM plugin_events WHERE post_date_gmt >= '2020-01-02T00:00:00Z'",
			) as $fallthrough_sql
		) {
			$translated = $translate->invoke( $driver, $tokenize->invoke( $driver, $fallthrough_sql ) );
			$this->assertStringNotContainsString( "TRY_CAST('2020-01-02T00:00:00Z' AS TIMESTAMP)", $translated, $fallthrough_sql );
		}
	}

	public function test_date_part_functions_try_cast_wordpress_datetime_strings(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				PRIMARY KEY (ID),
				KEY type_date (post_type, post_date)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_posts (post_date, post_type) VALUES
				('2026-06-26 14:15:16', 'attachment'),
				('2026-05-01 00:00:00', 'attachment'),
				('2026-04-01 00:00:00', 'post')"
		);

		$rows = $driver->query(
			"SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month, DAYOFMONTH(post_date) AS day
			FROM wp_posts
			WHERE post_type = 'attachment'
			ORDER BY post_date DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'year'  => 2026,
					'month' => 6,
					'day'   => 26,
				),
				array(
					'year'  => 2026,
					'month' => 5,
					'day'   => 1,
				),
			),
			$rows
		);

		$this->assertSame(
			array( array( 'ID' => 1 ) ),
			$driver->query(
				"SELECT ID
				FROM wp_posts
				WHERE YEAR(post_date) = 2026
					AND MONTH(post_date) = 6
					AND MONTHNUM(post_date) = 6
					AND DAYOFMONTH(post_date) = 26
					AND post_type = 'attachment'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'ID' => 1 ) ),
			$driver->query(
				"SELECT ID
				FROM wp_posts
				WHERE DATE_FORMAT(post_date, '%H.%i') = 14.15
					AND post_type = 'attachment'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$row = $driver->query(
			"SELECT HOUR(post_date) AS hour, MINUTE(post_date) AS minute, SECOND(post_date) AS second,
				DAYOFWEEK(post_date) AS day_of_week, WEEKDAY(post_date) AS weekday,
				WEEK(post_date, 1) AS week, DATE_FORMAT(post_date, '%Y-%m-%d %H:%i:%s') AS formatted,
				DATE_FORMAT(post_date, '%H.%i') AS hm
			FROM wp_posts
			WHERE post_date = '2026-06-26 14:15:16'"
		)->fetch( PDO::FETCH_ASSOC );

		$hour_minute = $row['hm'];
		unset( $row['hm'] );

		$this->assertSame(
			array(
				'hour'        => 14,
				'minute'      => 15,
				'second'      => 16,
				'day_of_week' => 6,
				'weekday'     => 4,
				'week'        => 26,
				'formatted'   => '2026-06-26 14:15:16',
			),
			$row
		);
		$this->assertEqualsWithDelta( 14.15, (float) $hour_minute, 0.000001 );

		$time_row = $driver->query( "SELECT HOUR('14:15:16') AS hour, MINUTE('14:15:16') AS minute, SECOND('14:15:16') AS second" )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				'hour'   => 14,
				'minute' => 15,
				'second' => 16,
			),
			$time_row
		);

		$row = $driver->query(
			"SELECT
				YEAR('0000-00-00 00:00:00') AS zero_date_year,
				MONTH('0000-00-00 00:00:00') AS zero_date_month,
				DAYOFMONTH('0000-00-00 00:00:00') AS zero_date_day,
				DATE('0000-00-00 00:00:00') AS zero_date_date,
				DATEDIFF('0000-00-00 00:00:00', '2026-06-26 14:15:16') AS zero_date_delta,
				YEAR(DATE '2026-07-01') AS date_year,
				MONTH(TIMESTAMP '2026-08-02 03:04:05') AS timestamp_month,
				DAY(TIMESTAMP '2026-08-02 03:04:05') AS timestamp_day"
		)->fetch( PDO::FETCH_ASSOC );

		$this->assertNull( $row['zero_date_year'] );
		$this->assertNull( $row['zero_date_month'] );
		$this->assertNull( $row['zero_date_day'] );
		$this->assertNull( $row['zero_date_date'] );
		$this->assertNull( $row['zero_date_delta'] );
		$this->assertSame( 2026, $row['date_year'] );
		$this->assertSame( 8, $row['timestamp_month'] );
		$this->assertSame( 2, $row['timestamp_day'] );
	}

	public function test_weekly_archive_date_format_select_uses_grouped_date_boundary(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
				(1, '2024-02-01 00:00:00', 'post', 'publish'),
				(2, '2024-02-03 00:00:00', 'post', 'publish'),
				(3, '2024-01-10 00:00:00', 'post', 'publish'),
				(4, '2024-02-04 00:00:00', 'page', 'publish'),
				(5, '2024-03-01 00:00:00', 'post', 'draft')"
		);

		$rows = $driver->query(
			"SELECT DISTINCT WEEK( `post_date`, 1 ) AS `week`,
				YEAR( `post_date` ) AS `yr`,
				DATE_FORMAT( `post_date`, '%Y-%m-%d' ) AS `yyyymmdd`,
				count( `ID` ) AS `posts`
			FROM `wptests_posts`
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY WEEK( `post_date`, 1 ), YEAR( `post_date` )
			ORDER BY `post_date` DESC"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'week'     => 5,
					'yr'       => 2024,
					'yyyymmdd' => '2024-02-03',
					'posts'    => 2,
				),
				array(
					'week'     => 2,
					'yr'       => 2024,
					'yyyymmdd' => '2024-01-10',
					'posts'    => 1,
				),
			),
			$rows
		);

		$this->assertStringContainsString(
			'strftime(TRY_CAST((MAX("post_date")) AS TIMESTAMP), \'%Y-%m-%d\') AS "yyyymmdd"',
			$this->lastDuckDBQuery( $driver )
		);
		$this->assertStringContainsString( 'ORDER BY MAX("post_date") DESC', $this->lastDuckDBQuery( $driver ) );
	}

	public function test_sum_length_result_does_not_require_bcmath(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload) VALUES
				('autoload_yes', 'abcd', 'yes'),
				('autoload_on', 'xy', 'on'),
				('autoload_auto', 'z', 'auto'),
				('manual', 'ignored', 'no')"
		);

		$row = $driver->query(
			"SELECT SUM(LENGTH(option_value)) FROM wptests_options
			WHERE autoload IN ('yes','on','auto-on','auto')"
		)->fetch( PDO::FETCH_NUM );

		$this->assertSame( 7, $row[0] );
		$this->assertStringContainsString( 'CAST(SUM(', $this->lastDuckDBQuery( $driver ) );
		$this->assertStringContainsString( ' AS BIGINT)', $this->lastDuckDBQuery( $driver ) );
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
			$this->assertSame( 'INSERT INTO "users"("name") VALUES (\'Ada\'), (\'Grace\') RETURNING "id"', $this->lastDuckDBQuery( $driver ) );

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

	public function test_show_full_tables_reports_table_type_and_like_filter(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'CREATE TABLE _tmp_table (id INT)' );
		$driver->query( 'CREATE TABLE _tmp_table_2 (id INT)' );

		$full = $driver->query( 'SHOW FULL TABLES' );
		$this->assertSame( 2, $full->columnCount() );
		$this->assertSame( array( 'name' => 'Tables_in_wp' ), $full->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => 'Table_type' ), $full->getColumnMeta( 1 ) );
		$this->assertSame(
			array(
				array(
					'Tables_in_wp' => '_tmp_table',
					'Table_type'   => 'BASE TABLE',
				),
				array(
					'Tables_in_wp' => '_tmp_table_2',
					'Table_type'   => 'BASE TABLE',
				),
			),
			$full->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Tables_in_wp' => '_tmp_table',
					'Table_type'   => 'BASE TABLE',
				),
			),
			$driver->query( "SHOW FULL TABLES LIKE '_tmp_table'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'Tables_in_wp' => '_tmp_table' ) ),
			$driver->query( "SHOW TABLES LIKE '_tmp_table'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query( "SHOW FULL TABLES LIKE '__wp_duckdb_%'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query( 'SHOW FULL TABLES FROM other_database' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_create_and_show_create_view_remain_explicitly_unsupported(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE view_source (id INT, name VARCHAR(20))' );

		foreach (
			array(
				'CREATE VIEW visible_view AS SELECT id, name FROM view_source' => 'CREATE VIEW statement',
				'CREATE OR REPLACE VIEW visible_view AS SELECT id FROM view_source' => 'CREATE VIEW statement',
				'CREATE VIEW internal_view AS SELECT table_name FROM information_schema.tables' => 'CREATE VIEW statement',
				'SHOW CREATE VIEW visible_view' => 'SHOW CREATE VIEW statement',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected view lifecycle statement to be unsupported: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
				$this->assertStringContainsString( 'view lifecycle metadata is not supported', strtolower( $e->getMessage() ) );
			}
		}
	}

	public function test_native_views_can_be_selected_and_dropped_but_remain_omitted_from_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE view_source (id INT, name VARCHAR(20))' );
		$driver->query( "INSERT INTO view_source (id, name) VALUES (1, 'Ada'), (2, 'Grace')" );
		$driver->get_connection()->query( 'CREATE VIEW "native_view" AS SELECT id, name FROM "view_source"' );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'Ada',
				),
				array(
					'id'   => 2,
					'name' => 'Grace',
				),
			),
			$driver->query( 'SELECT id, name FROM native_view ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( array(), $driver->query( "SHOW TABLES LIKE 'native_view'" )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->query( "SHOW FULL TABLES LIKE 'native_view'" )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->query( "SHOW TABLE STATUS LIKE 'native_view'" )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT TABLE_NAME, TABLE_TYPE
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp' AND TABLE_NAME = 'native_view'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->query( 'SHOW CREATE TABLE native_view' )->fetchAll( PDO::FETCH_ASSOC ) );

		$this->assertSame(
			array(
				array(
					'Tables_in_wp' => 'view_source',
					'Table_type'   => 'BASE TABLE',
				),
			),
			$driver->query( 'SHOW FULL TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'TABLE_NAME' => 'view_source',
					'TABLE_TYPE' => 'BASE TABLE',
				),
			),
			$driver->query(
				"SELECT TABLE_NAME, TABLE_TYPE
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
				ORDER BY TABLE_NAME"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'DROP VIEW native_view' )->rowCount() );
		try {
			$driver->query( 'SELECT id FROM native_view' );
			$this->fail( 'Expected dropped native view to be unavailable.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'native_view', $e->getMessage() );
		}

		try {
			$driver->query( 'DROP VIEW missing_native_view' );
			$this->fail( 'Expected DROP VIEW to report a missing native view.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'missing_native_view', $e->getMessage() );
		}
		$this->assertSame( 0, $driver->query( 'DROP VIEW IF EXISTS missing_native_view' )->rowCount() );
	}

	public function test_drop_view_lifecycle_validation_is_bounded(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		foreach (
			array(
				'DROP VIEW information_schema.tables'   => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				'DROP VIEW __wp_duckdb_column_metadata' => 'Internal DuckDB metadata tables cannot be modified',
				'DROP VIEW other_database.native_view'  => 'Only the current database is supported',
				'DROP VIEW first_view, second_view'     => 'Only a single view target is supported',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected DROP VIEW validation to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_show_admin_metadata_statements_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$collations = $driver->query( 'SHOW COLLATION' );
		$this->assertSame( 7, $collations->columnCount() );
		$this->assertSame( 0, $collations->rowCount() );
		$this->assertSame( array( 'name' => 'Collation' ), $collations->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => 'Charset' ), $collations->getColumnMeta( 1 ) );
		$this->assertSame( array( 'name' => 'Id' ), $collations->getColumnMeta( 2 ) );
		$this->assertSame( array( 'name' => 'Default' ), $collations->getColumnMeta( 3 ) );
		$this->assertSame( array( 'name' => 'Compiled' ), $collations->getColumnMeta( 4 ) );
		$this->assertSame( array( 'name' => 'Sortlen' ), $collations->getColumnMeta( 5 ) );
		$this->assertSame( array( 'name' => 'Pad_attribute' ), $collations->getColumnMeta( 6 ) );
		$this->assertSame(
			array(
				array(
					'Collation'     => 'binary',
					'Charset'       => 'binary',
					'Id'            => 63,
					'Default'       => 'Yes',
					'Compiled'      => 'Yes',
					'Sortlen'       => 1,
					'Pad_attribute' => 'NO PAD',
				),
				array(
					'Collation'     => 'utf8_bin',
					'Charset'       => 'utf8',
					'Id'            => 83,
					'Default'       => '',
					'Compiled'      => 'Yes',
					'Sortlen'       => 1,
					'Pad_attribute' => 'PAD SPACE',
				),
				array(
					'Collation'     => 'utf8_general_ci',
					'Charset'       => 'utf8',
					'Id'            => 33,
					'Default'       => 'Yes',
					'Compiled'      => 'Yes',
					'Sortlen'       => 1,
					'Pad_attribute' => 'PAD SPACE',
				),
				array(
					'Collation'     => 'utf8_unicode_ci',
					'Charset'       => 'utf8',
					'Id'            => 192,
					'Default'       => '',
					'Compiled'      => 'Yes',
					'Sortlen'       => 8,
					'Pad_attribute' => 'PAD SPACE',
				),
				array(
					'Collation'     => 'utf8mb4_bin',
					'Charset'       => 'utf8mb4',
					'Id'            => 46,
					'Default'       => '',
					'Compiled'      => 'Yes',
					'Sortlen'       => 1,
					'Pad_attribute' => 'PAD SPACE',
				),
				array(
					'Collation'     => 'utf8mb4_unicode_ci',
					'Charset'       => 'utf8mb4',
					'Id'            => 224,
					'Default'       => '',
					'Compiled'      => 'Yes',
					'Sortlen'       => 8,
					'Pad_attribute' => 'PAD SPACE',
				),
				array(
					'Collation'     => 'utf8mb4_0900_ai_ci',
					'Charset'       => 'utf8mb4',
					'Id'            => 255,
					'Default'       => 'Yes',
					'Compiled'      => 'Yes',
					'Sortlen'       => 0,
					'Pad_attribute' => 'NO PAD',
				),
			),
			$collations->fetchAll( PDO::FETCH_ASSOC )
		);

		$utf8_collations = $driver->query( "SHOW COLLATION LIKE 'utf8%'" );
		$this->assertSame( 0, $utf8_collations->rowCount() );
		$this->assertSame(
			array( 'utf8_bin', 'utf8_general_ci', 'utf8_unicode_ci', 'utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci' ),
			array_column( $utf8_collations->fetchAll( PDO::FETCH_ASSOC ), 'Collation' )
		);
		$this->assertSame(
			array( array( 'found_rows' => 6 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$filtered_collations = $driver->query( "SHOW COLLATION WHERE Collation = 'utf8_bin'" );
		$this->assertSame( 0, $filtered_collations->rowCount() );
		$this->assertSame(
			array(
				array(
					'Collation'     => 'utf8_bin',
					'Charset'       => 'utf8',
					'Id'            => 83,
					'Default'       => '',
					'Compiled'      => 'Yes',
					'Sortlen'       => 1,
					'Pad_attribute' => 'PAD SPACE',
				),
			),
			$filtered_collations->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$missing_collations = $driver->query( "SHOW COLLATION LIKE 'missing%'" );
		$this->assertSame( 0, $missing_collations->rowCount() );
		$this->assertSame( array(), $missing_collations->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$databases = $driver->query( 'SHOW DATABASES' );
		$this->assertSame( 1, $databases->columnCount() );
		$this->assertSame( 0, $databases->rowCount() );
		$this->assertSame( array( 'name' => 'Database' ), $databases->getColumnMeta( 0 ) );
		$this->assertSame(
			array(
				array( 'Database' => 'information_schema' ),
				array( 'Database' => 'wp' ),
			),
			$databases->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'Database' => 'wp' ) ),
			$driver->query( 'SHOW DATABASES LIKE "w%"' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'Database' => 'information_schema' ) ),
			$driver->query( 'SHOW DATABASES WHERE `Database` = "information_schema"' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$schemas = $driver->query( 'SHOW SCHEMAS' );
		$this->assertSame( 1, $schemas->columnCount() );
		$this->assertSame( 0, $schemas->rowCount() );
		$this->assertSame( array( 'name' => 'Database' ), $schemas->getColumnMeta( 0 ) );
		$this->assertSame(
			array(
				array( 'Database' => 'information_schema' ),
				array( 'Database' => 'wp' ),
			),
			$schemas->fetchAll( PDO::FETCH_ASSOC )
		);

		$filtered_schemas = $driver->query( "SHOW SCHEMAS LIKE 'wp'" );
		$this->assertSame( 0, $filtered_schemas->rowCount() );
		$this->assertSame(
			array( array( 'Database' => 'wp' ) ),
			$filtered_schemas->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$missing_schemas = $driver->query( "SHOW SCHEMAS WHERE `Database` = 'missing'" );
		$this->assertSame( 0, $missing_schemas->rowCount() );
		$this->assertSame( array(), $missing_schemas->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		foreach (
			array(
				'SHOW GRANTS',
				'SHOW GRANTS FOR current_user()',
				'SHOW GRANTS FOR CURRENT_USER',
				'SHOW GRANTS FOR root@localhost',
				"SHOW GRANTS FOR 'root'@'localhost'",
				'SHOW GRANTS FOR usera@localhost',
				'SHOW GRANTS FOR root',
			) as $sql
		) {
			$grants = $driver->query( $sql );
			$this->assertSame( 1, $grants->columnCount() );
			$this->assertSame( 0, $grants->rowCount() );
			$this->assertSame( array( 'name' => 'Grants for root@%' ), $grants->getColumnMeta( 0 ) );
			$this->assertSame(
				array(
					array(
						'Grants for root@%' => 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, RELOAD, SHUTDOWN, PROCESS, FILE, REFERENCES, INDEX, ALTER, SHOW DATABASES, SUPER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, REPLICATION SLAVE, REPLICATION CLIENT, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, CREATE USER, EVENT, TRIGGER, CREATE TABLESPACE, CREATE ROLE, DROP ROLE ON *.* TO `root`@`localhost` WITH GRANT OPTION',
					),
				),
				$grants->fetchAll( PDO::FETCH_ASSOC )
			);
		}
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		foreach (
			array(
				'SHOW VARIABLES',
				"SHOW VARIABLES LIKE 'version'",
				"SHOW VARIABLES WHERE Variable_name = 'version'",
				'SHOW GLOBAL VARIABLES',
				'SHOW SESSION VARIABLES',
				'SHOW LOCAL VARIABLES',
				"SHOW GLOBAL VARIABLES LIKE 'version'",
				"SHOW SESSION VARIABLES WHERE Variable_name = 'version'",
				"SHOW LOCAL VARIABLES WHERE Variable_name = 'version'",
			) as $sql
		) {
			$variables = $driver->query( $sql );
			$this->assertSame( 2, $variables->columnCount() );
			$this->assertSame( 0, $variables->rowCount() );
			$this->assertSame( array( 'name' => 'Variable_name' ), $variables->getColumnMeta( 0 ) );
			$this->assertSame( array( 'name' => 'Value' ), $variables->getColumnMeta( 1 ) );
			$this->assertSame( array(), $variables->fetchAll( PDO::FETCH_ASSOC ) );
		}
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_show_grants_rejects_unsupported_like_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$this->assertDriverQueryRejected( $driver, "SHOW GRANTS LIKE '%'" );
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

	public function test_wordpress_phpunit_boilerplate_statements_accept_fast_path_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE tx_fast_path (id INT)' );

		$set = $driver->query( " \n SET   autocommit=0; \t" );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array( '@@autocommit' => 0 ),
			$driver->query( 'SELECT @@autocommit' )->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( 'start transaction;' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'BEGIN TRANSACTION' ), $driver->get_last_duckdb_queries() );

		$driver->query( 'INSERT INTO tx_fast_path (id) VALUES (1)' );
		$driver->query( 'commit work;' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'COMMIT' ), $driver->get_last_duckdb_queries() );

		$driver->query( 'BEGIN;' );
		$driver->query( 'INSERT INTO tx_fast_path (id) VALUES (2)' );
		$driver->query( 'rollback WORK;' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'ROLLBACK' ), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array( array( 'id' => 1 ) ),
			$driver->query( 'SELECT id FROM tx_fast_path ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'BEGIN' );
		$this->assertDriverQueryRejected(
			$driver,
			'ROLLBACK TO sp1;',
			'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.'
		);
		$driver->query( 'ROLLBACK' );
	}

	public function test_native_error_rolls_back_active_transaction_before_dbdelta_insert(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE wptests_dbdelta_test (
				id int NOT NULL AUTO_INCREMENT,
				column_1 varchar(255),
				PRIMARY KEY (id)
			)'
		);

		$driver->query( 'BEGIN' );
		try {
			$driver->query( "SELECT '\xF0\x9F' AS invalid_bytes" );
			$this->fail( 'Expected invalid UTF-8 SELECT to fail.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Invalid unicode', $e->getMessage() );
		}

		$this->assertFalse( $driver->get_connection()->inTransaction() );

		$driver->query( "INSERT INTO wptests_dbdelta_test (column_1) VALUES ('wcphilly2015')" );
		$row = $driver->query( "select column_1 from wptests_dbdelta_test where column_1 = 'wcphilly2015'" )->fetch( PDO::FETCH_OBJ );

		$this->assertIsObject( $row );
		$this->assertSame( 'wcphilly2015', $row->column_1 );
	}

	public function test_savepoint_sql_is_rejected_without_mutating_active_transaction(): void {
		$this->requireDuckDBRuntime();

		foreach (
			array(
				array(
					'sql'     => 'SAVEPOINT sp1',
					'message' => 'Unsupported DuckDB MySQL-emulation statement: SAVEPOINT.',
					'finish'  => 'COMMIT',
				),
				array(
					'sql'     => 'ROLLBACK TO sp1',
					'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
					'finish'  => 'ROLLBACK',
				),
				array(
					'sql'     => 'ROLLBACK TO SAVEPOINT sp1',
					'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
					'finish'  => 'COMMIT',
				),
				array(
					'sql'     => 'ROLLBACK WORK TO sp1',
					'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
					'finish'  => 'ROLLBACK',
				),
				array(
					'sql'     => 'ROLLBACK WORK TO SAVEPOINT sp1',
					'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
					'finish'  => 'COMMIT',
				),
				array(
					'sql'     => 'RELEASE SAVEPOINT sp1',
					'message' => 'Unsupported DuckDB MySQL-emulation statement: RELEASE.',
					'finish'  => 'ROLLBACK',
				),
			) as $case
		) {
			$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
			$driver->query( 'CREATE TABLE tx_savepoint_state (id INT)' );

			$driver->query( 'BEGIN' );
			$driver->query( 'INSERT INTO tx_savepoint_state (id) VALUES (1)' );

			$this->assertDriverQueryRejected( $driver, $case['sql'], $case['message'] );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
			$this->assertTrue( $driver->get_connection()->inTransaction() );
			$this->assertSame(
				array( array( 'id' => 1 ) ),
				$driver->query( 'SELECT id FROM tx_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$driver->query( 'INSERT INTO tx_savepoint_state (id) VALUES (2)' );
			$this->assertTrue( $driver->get_connection()->inTransaction() );
			$this->assertSame(
				array(
					array( 'id' => 1 ),
					array( 'id' => 2 ),
				),
				$driver->query( 'SELECT id FROM tx_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertDriverQueryRejected(
				$driver,
				'ALTER TABLE tx_savepoint_state ADD PRIMARY KEY (id)',
				'ADD PRIMARY KEY cannot run inside an active DuckDB transaction'
			);
			foreach ( $driver->get_last_duckdb_queries() as $duckdb_sql ) {
				$this->assertStringNotContainsString( 'ALTER TABLE', $duckdb_sql );
				$this->assertStringNotContainsString( 'ADD PRIMARY KEY', $duckdb_sql );
				$this->assertStringNotContainsString( 'COMMIT', $duckdb_sql );
				$this->assertStringNotContainsString( 'ROLLBACK', $duckdb_sql );
			}
			$this->assertTrue( $driver->get_connection()->inTransaction() );
			$this->assertSame( '', $driver->query( 'DESCRIBE tx_savepoint_state' )->fetch( PDO::FETCH_ASSOC )['Key'] );
			$driver->query( 'INSERT INTO tx_savepoint_state (id) VALUES (3)' );
			$this->assertTrue( $driver->get_connection()->inTransaction() );

			$driver->query( $case['finish'] );
			$this->assertFalse( $driver->get_connection()->inTransaction() );
			if ( 'COMMIT' === $case['finish'] ) {
				$this->assertSame(
					array(
						array( 'id' => 1 ),
						array( 'id' => 2 ),
						array( 'id' => 3 ),
					),
					$driver->query( 'SELECT id FROM tx_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
				);
			} else {
				$this->assertSame(
					array(),
					$driver->query( 'SELECT id FROM tx_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
				);
			}
		}
	}

	public function test_savepoint_sql_is_rejected_without_starting_transaction_or_mutating_table(): void {
		$this->requireDuckDBRuntime();

		$cases = array(
			array(
				'sql'     => 'SAVEPOINT sp1',
				'message' => 'Unsupported DuckDB MySQL-emulation statement: SAVEPOINT.',
			),
			array(
				'sql'     => 'ROLLBACK TO sp1',
				'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
			),
			array(
				'sql'     => 'ROLLBACK TO SAVEPOINT sp1',
				'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
			),
			array(
				'sql'     => 'ROLLBACK WORK TO sp1',
				'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
			),
			array(
				'sql'     => 'ROLLBACK WORK TO SAVEPOINT sp1',
				'message' => 'Unsupported ROLLBACK statement in DuckDB driver. Only ROLLBACK [WORK] is supported.',
			),
			array(
				'sql'     => 'RELEASE SAVEPOINT sp1',
				'message' => 'Unsupported DuckDB MySQL-emulation statement: RELEASE.',
			),
		);

		foreach ( $cases as $case ) {
			$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
			$driver->query( 'CREATE TABLE savepoint_reject_state (id INT)' );
			$driver->query( 'INSERT INTO savepoint_reject_state (id) VALUES (1)' );

			$this->assertFalse( $driver->get_connection()->inTransaction() );
			$this->assertDriverQueryRejected( $driver, $case['sql'], $case['message'] );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
			$this->assertFalse( $driver->get_connection()->inTransaction() );
			$this->assertSame(
				array( array( 'id' => 1 ) ),
				$driver->query( 'SELECT id FROM savepoint_reject_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
			);
		}
	}

	public function test_session_boolean_variables_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

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
			$set = $driver->query( $sql );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );

			$read = $driver->query( 'SELECT @@autocommit, @@big_tables' );
			$this->assertSame( 2, $read->columnCount() );
			$this->assertSame( array( 'name' => '@@autocommit' ), $read->getColumnMeta( 0 ) );
			$this->assertSame( array( 'name' => '@@big_tables' ), $read->getColumnMeta( 1 ) );
			$this->assertSame(
				array(
					'@@autocommit' => 1,
					'@@big_tables' => 0,
				),
				$read->fetch( PDO::FETCH_ASSOC )
			);
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}

		$set = $driver->query( 'SET autocommit = OFF' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame(
			array( '@@autocommit' => 0 ),
			$driver->query( 'SELECT @@autocommit' )->fetch( PDO::FETCH_ASSOC )
		);

		$set = $driver->query( 'SET big_tables = ON' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame(
			array( '@@big_tables' => 1 ),
			$driver->query( 'SELECT @@big_tables' )->fetch( PDO::FETCH_ASSOC )
		);
	}

	public function test_session_variable_scoped_forms_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$read = $driver->query( 'SELECT @@session.autocommit, @@SESSION.big_tables' );
		$this->assertSame(
			array(
				'@@session.autocommit' => null,
				'@@SESSION.big_tables' => null,
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array( 'name' => '@@session.autocommit' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => '@@SESSION.big_tables' ), $read->getColumnMeta( 1 ) );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		foreach (
			array(
				'SET SESSION autocommit = 0',
				'SET @@session.big_tables = 1',
			) as $sql
		) {
			$set = $driver->query( $sql );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}

		$this->assertSame(
			array(
				'@@autocommit'         => 0,
				'@@SESSION.autocommit' => 0,
				'@@big_tables'         => 1,
				'@@session.big_tables' => 1,
			),
			$driver->query(
				'SELECT @@autocommit, @@SESSION.autocommit, @@big_tables, @@session.big_tables'
			)->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$this->assertSame(
			array(
				'ac'                        => 0,
				'@@autocommit + 0'          => 0,
				'COALESCE(@@autocommit, 1)' => 0,
			),
			$driver->query(
				'SELECT @@autocommit AS ac, @@autocommit + 0, COALESCE(@@autocommit, 1)'
			)->fetch( PDO::FETCH_ASSOC )
		);
	}

	public function test_session_variable_scoped_comma_list_matches_sqlite_current_behavior(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$set = $driver->query( 'SET SESSION autocommit = 1, big_tables = 0' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$read = $driver->query( 'SELECT @@autocommit, @@session.autocommit, @@big_tables, @@session.big_tables' );
		$this->assertSame(
			array(
				'@@autocommit'         => 1,
				'@@session.autocommit' => 1,
				'@@big_tables'         => null,
				'@@session.big_tables' => null,
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_session_variable_default_matches_sqlite_current_behavior(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		foreach (
			array(
				'SET autocommit = DEFAULT',
				'SET @@session.big_tables = DEFAULT',
			) as $sql
		) {
			$set = $driver->query( $sql );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}

		$this->assertSame(
			array(
				'@@autocommit'         => 'DEFAULT',
				'@@session.big_tables' => 'DEFAULT',
			),
			$driver->query( 'SELECT @@autocommit, @@session.big_tables' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_default_storage_engine_session_variable_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$set = $driver->query( 'SET default_storage_engine = InnoDB' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$read = $driver->query( 'SELECT @@default_storage_engine, @@SESSION.default_storage_engine' );
		$this->assertSame( array( 'name' => '@@default_storage_engine' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => '@@SESSION.default_storage_engine' ), $read->getColumnMeta( 1 ) );
		$this->assertSame(
			array(
				'@@default_storage_engine'         => 'InnoDB',
				'@@SESSION.default_storage_engine' => 'InnoDB',
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( "SET @@session.default_storage_engine = 'MyISAM'" );
		$this->assertSame(
			array( '@@default_storage_engine' => 'MyISAM' ),
			$driver->query( 'SELECT @@default_storage_engine' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( 'SET SESSION default_storage_engine = DEFAULT' );
		$this->assertSame(
			array( '@@default_storage_engine' => 'DEFAULT' ),
			$driver->query( 'SELECT @@default_storage_engine' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_keyword_session_variables_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$set = $driver->query(
			'SET default_collation_for_utf8mb4 = utf8mb4_0900_ai_ci,
				resultset_metadata = FULL,
				session_track_gtids = OWN_GTID,
				session_track_transaction_info = STATE,
				transaction_isolation = SERIALIZABLE,
				use_secondary_engine = FORCED'
		);
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$read = $driver->query(
			'SELECT @@default_collation_for_utf8mb4,
				@@resultset_metadata,
				@@session_track_gtids,
				@@session_track_transaction_info,
				@@transaction_isolation,
				@@use_secondary_engine'
		);
		$this->assertSame( array( 'name' => '@@default_collation_for_utf8mb4' ), $read->getColumnMeta( 0 ) );
		$this->assertSame(
			array(
				'@@default_collation_for_utf8mb4'  => 'utf8mb4_0900_ai_ci',
				'@@resultset_metadata'             => 'FULL',
				'@@session_track_gtids'            => 'OWN_GTID',
				'@@session_track_transaction_info' => 'STATE',
				'@@transaction_isolation'          => 'SERIALIZABLE',
				'@@use_secondary_engine'           => 'FORCED',
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( "SET @@session.session_track_transaction_info = 'CHARACTERISTICS'" );
		$this->assertSame(
			array(
				'@@session.session_track_transaction_info' => 'CHARACTERISTICS',
			),
			$driver->query( 'SELECT @@session.session_track_transaction_info' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_boolean_like_session_variables_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver      = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$assignments = array(
			'end_markers_in_json'                     => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'explicit_defaults_for_timestamp'         => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'keep_files_on_create'                    => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'old_alter_table'                         => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'print_identified_with_as_hex'            => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'require_row_format'                      => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'select_into_disk_sync'                   => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'session_track_schema'                    => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'session_track_state_change'              => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'show_create_table_skip_secondary_engine' => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'show_create_table_verbosity'             => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'sql_auto_is_null'                        => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'sql_big_selects'                         => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'sql_buffer_result'                       => array(
				'value'    => 'ON',
				'expected' => 1,
			),
			'sql_safe_updates'                        => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
			'transaction_read_only'                   => array(
				'value'    => 'OFF',
				'expected' => 0,
			),
		);

		foreach ( $assignments as $name => $assignment ) {
			$set = $driver->query( 'SET ' . $name . ' = ' . $assignment['value'] );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}

		$expected = array();
		foreach ( $assignments as $name => $assignment ) {
			$expected[ '@@' . $name ] = $assignment['expected'];
		}

		$read = $driver->query( 'SELECT @@' . implode( ', @@', array_keys( $assignments ) ) );
		$this->assertSame( $expected, $read->fetch( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( 'SET @old_safe_updates = @@sql_safe_updates' );
		$driver->query( 'SET @@sql_safe_updates = ON' );
		$driver->query( 'SET @@sql_safe_updates = @old_safe_updates' );
		$this->assertSame(
			array( '@@sql_safe_updates' => 0 ),
			$driver->query( 'SELECT @@sql_safe_updates' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_sql_mode_bootstrap_statements_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		foreach (
			array(
				'SET NAMES utf8mb4',
				'SET CHARSET utf8mb4',
				'SET CHARACTER SET utf8mb4',
			) as $sql
		) {
			$set = $driver->query( $sql );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}

		$default_sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,'
			. 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';
		$read             = $driver->query( 'SELECT @@SESSION.sql_mode, @@sql_mode' );
		$this->assertSame( array( 'name' => '@@SESSION.sql_mode' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => '@@sql_mode' ), $read->getColumnMeta( 1 ) );
		$this->assertSame(
			array(
				'@@SESSION.sql_mode' => $default_sql_mode,
				'@@sql_mode'         => $default_sql_mode,
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$set = $driver->query( 'SET NAMES utf8mb4, autocommit = 0' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array( '@@autocommit' => 0 ),
			$driver->query( 'SELECT @@autocommit' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$set = $driver->query( "SET CHARACTER SET utf8mb4, sql_mode = 'NO_ZERO_DATE'" );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array(
				'@@SESSION.sql_mode' => 'NO_ZERO_DATE',
				'@@sql_mode'         => 'NO_ZERO_DATE',
			),
			$driver->query( 'SELECT @@SESSION.sql_mode, @@sql_mode' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		foreach (
			array(
				array(
					'sql'      => "SET SESSION sql_mode = ''",
					'expected' => '',
				),
				array(
					'sql'      => "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'",
					'expected' => 'NO_ENGINE_SUBSTITUTION',
				),
				array(
					'sql'      => "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
					'expected' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',
				),
			) as $case
		) {
			$set = $driver->query( $case['sql'] );
			$this->assertSame( 0, $set->rowCount() );
			$this->assertSame( 0, $set->columnCount() );
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );

			$this->assertSame(
				array(
					'@@SESSION.sql_mode' => $case['expected'],
					'@@sql_mode'         => $case['expected'],
				),
				$driver->query( 'SELECT @@SESSION.sql_mode, @@sql_mode' )->fetch( PDO::FETCH_ASSOC )
			);
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}
	}

	public function test_aliased_sql_mode_select_expressions_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES, NO_ZERO_DATE, NO_ZERO_IN_DATE'" );

		foreach (
			array(
				array(
					'sql'   => 'SELECT @@SESSION.sql_mode AS sql_mode',
					'alias' => 'sql_mode',
				),
				array(
					'sql'   => 'SELECT @@sql_mode AS mode',
					'alias' => 'mode',
				),
				array(
					'sql'   => 'SELECT @@ SESSION.sql_mode AS spaced_sql_mode',
					'alias' => 'spaced_sql_mode',
				),
			) as $case
		) {
			$read = $driver->query( $case['sql'] );
			$this->assertSame( array( 'name' => $case['alias'] ), $read->getColumnMeta( 0 ) );
			$this->assertSame(
				array( $case['alias'] => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE' ),
				$read->fetch( PDO::FETCH_ASSOC )
			);
			$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		}
	}

	public function test_builtin_system_variables_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$read   = $driver->query( 'SELECT @@version, @@version_comment' );

		$this->assertSame( 0, $read->rowCount() );
		$this->assertSame( array( 'name' => '@@version' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => '@@version_comment' ), $read->getColumnMeta( 1 ) );
		$this->assertSame(
			array(
				'@@version'         => '8.0.38',
				'@@version_comment' => 'MySQL Community Server - GPL',
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertFalse( $read->fetch( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_scoped_system_variable_read_probes_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver           = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$default_sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,'
			. 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';

		$read = $driver->query(
			'SELECT @@GLOBAL.gtid_purged,
				@@GLOBAL.log_bin,
				@@GLOBAL.log_bin_trust_function_creators,
				@@GLOBAL.sql_mode,
				@@SESSION.max_allowed_packet,
				@@SESSION.sql_mode'
		);
		$this->assertSame(
			array(
				'@@GLOBAL.gtid_purged'                     => null,
				'@@GLOBAL.log_bin'                         => null,
				'@@GLOBAL.log_bin_trust_function_creators' => null,
				'@@GLOBAL.sql_mode'                        => $default_sql_mode,
				'@@SESSION.max_allowed_packet'             => null,
				'@@SESSION.sql_mode'                       => $default_sql_mode,
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( "SET SESSION sql_mode = 'NO_ZERO_DATE'" );
		$read = $driver->query( 'SELECT @@gLoBAL.gTiD_purGed, @@sEssIOn.sqL_moDe' );
		$this->assertSame( array( 'name' => '@@gLoBAL.gTiD_purGed' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => '@@sEssIOn.sqL_moDe' ), $read->getColumnMeta( 1 ) );
		$this->assertSame(
			array(
				'@@gLoBAL.gTiD_purGed' => null,
				'@@sEssIOn.sqL_moDe'   => 'NO_ZERO_DATE',
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
	}

	public function test_user_variables_are_emulated_for_bounded_values(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		$read = $driver->query( 'SELECT @missing, @missing AS missing_alias, @missing implicit_alias' );
		$this->assertSame( array( 'name' => '@missing' ), $read->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => 'missing_alias' ), $read->getColumnMeta( 1 ) );
		$this->assertSame( array( 'name' => 'implicit_alias' ), $read->getColumnMeta( 2 ) );
		$this->assertSame(
			array(
				'@missing'       => null,
				'missing_alias'  => null,
				'implicit_alias' => null,
			),
			$read->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$set = $driver->query(
			"SET @my_var = 1, @name := 'Ada', @copy = @name, @mode = @@SQL_MODE, @nothing = NULL"
		);
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$default_sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,'
			. 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';
		$this->assertSame(
			array(
				'@MY_VAR' => 1,
				'name'    => 'Ada',
				'@copy'   => 'Ada',
				'mode'    => $default_sql_mode,
				'nothing' => null,
			),
			$driver->query(
				'SELECT @MY_VAR, @name AS name, @copy, @mode mode, @nothing AS nothing FROM DUAL'
			)->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( 'SET @signed = -2, @decimal = +1.25, @flag = TRUE' );
		$this->assertSame(
			array(
				'@signed'  => -2,
				'@decimal' => 1.25,
				'@flag'    => 1,
			),
			$driver->query( 'SELECT @signed, @decimal, @flag' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$driver->query( 'SET @my_var = @my_var + 1' );
		$this->assertSame(
			array( '@my_var' => 2 ),
			$driver->query( 'SELECT @my_var' )->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SET @my_var = @my_var + 1' );
		$this->assertSame(
			array( '@my_var' => 3 ),
			$driver->query( 'SELECT @my_var' )->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SET @other = 4, @sum = @my_var + @other' );
		$this->assertSame(
			array( '@sum' => 7 ),
			$driver->query( 'SELECT @sum' )->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SET @db = DATABASE(), @version = VERSION()' );
		$this->assertSame(
			array(
				'@db'      => 'wp',
				'@version' => '8.0.38',
			),
			$driver->query( 'SELECT @db, @version' )->fetch( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				'alias' => 3,
				1       => 1,
			),
			$driver->query( 'SELECT @my_var AS alias, 1' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( '@my_var + 1' => 4 ),
			$driver->query( 'SELECT @my_var + 1' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'expr_var' => 4 ),
			$driver->query( 'SELECT @my_var + 1 AS expr_var' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'COALESCE(@my_var, 1)' => 3 ),
			$driver->query( 'SELECT COALESCE(@my_var, 1)' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'COALESCE(@missing, 1)' => 1 ),
			$driver->query( 'SELECT COALESCE(@missing, 1)' )->fetch( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				'alias' => 3,
				1       => 1,
			),
			$driver->query( 'SELECT @my_var AS alias, 1 FROM DUAL' )->fetch( PDO::FETCH_ASSOC )
		);
	}

	public function test_dump_check_variable_backup_and_restore_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"SET character_set_client = 'latin1',
				character_set_results = 'latin1',
				collation_connection = latin1_swedish_ci,
				time_zone = '+02:00',
				sql_notes = 1"
		);

		$default_sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,'
			. 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';
		$driver->query( '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' );
		$driver->query( '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;' );
		$driver->query( '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;' );
		$driver->query( '/*!50503 SET NAMES utf8mb4 */;' );
		$driver->query( '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' );
		$driver->query( "/*!40103 SET TIME_ZONE='+00:00' */;" );
		$set = $driver->query( '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;' );
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array(
				'@OLD_UNIQUE_CHECKS' => null,
				'@@UNIQUE_CHECKS'    => 0,
			),
			$driver->query( 'SELECT @OLD_UNIQUE_CHECKS, @@UNIQUE_CHECKS' )->fetch( PDO::FETCH_ASSOC )
		);

		$set = $driver->query(
			'/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;'
		);
		$this->assertSame( 0, $set->rowCount() );
		$this->assertSame( 0, $set->columnCount() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );
		$this->assertSame(
			array(
				'@OLD_FOREIGN_KEY_CHECKS' => null,
				'@@FOREIGN_KEY_CHECKS'    => 0,
			),
			$driver->query( 'SELECT @OLD_FOREIGN_KEY_CHECKS, @@FOREIGN_KEY_CHECKS' )->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;" );
		$driver->query( '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' );
		$driver->query( '/*!40101 SET @saved_cs_client = @@character_set_client */; ' );
		$driver->query( '/*!50503 SET character_set_client = utf8mb4 */;' );
		$this->assertSame(
			array(
				'@OLD_CHARACTER_SET_CLIENT'  => 'latin1',
				'@OLD_CHARACTER_SET_RESULTS' => 'latin1',
				'@OLD_COLLATION_CONNECTION'  => 'latin1_swedish_ci',
				'@OLD_TIME_ZONE'             => '+02:00',
				'@OLD_SQL_MODE'              => $default_sql_mode,
				'@OLD_SQL_NOTES'             => 1,
				'@saved_cs_client'           => 'latin1',
				'@@CHARACTER_SET_CLIENT'     => 'utf8mb4',
				'@@TIME_ZONE'                => '+00:00',
				'@@SQL_MODE'                 => 'NO_AUTO_VALUE_ON_ZERO',
				'@@SQL_NOTES'                => 0,
			),
			$driver->query(
				'SELECT @OLD_CHARACTER_SET_CLIENT,
					@OLD_CHARACTER_SET_RESULTS,
					@OLD_COLLATION_CONNECTION,
					@OLD_TIME_ZONE,
					@OLD_SQL_MODE,
					@OLD_SQL_NOTES,
					@saved_cs_client,
					@@CHARACTER_SET_CLIENT,
					@@TIME_ZONE,
					@@SQL_MODE,
					@@SQL_NOTES'
			)->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( '/*!40101 SET character_set_client = @saved_cs_client */;' );
		$driver->query( '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;' );
		$driver->query( '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;' );
		$driver->query( '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;' );
		$driver->query( '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;' );
		$driver->query( '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;' );
		$driver->query( '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;' );
		$driver->query( '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;' );
		$driver->query( '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;' );
		$this->assertSame(
			array(
				'@@CHARACTER_SET_CLIENT'  => 'latin1',
				'@@CHARACTER_SET_RESULTS' => 'latin1',
				'@@COLLATION_CONNECTION'  => 'latin1_swedish_ci',
				'@@TIME_ZONE'             => '+02:00',
				'@@SQL_MODE'              => $default_sql_mode,
				'@@UNIQUE_CHECKS'         => null,
				'@@FOREIGN_KEY_CHECKS'    => null,
				'@@SQL_NOTES'             => 1,
			),
			$driver->query(
				'SELECT @@CHARACTER_SET_CLIENT,
					@@CHARACTER_SET_RESULTS,
					@@COLLATION_CONNECTION,
					@@TIME_ZONE,
					@@SQL_MODE,
					@@UNIQUE_CHECKS,
					@@FOREIGN_KEY_CHECKS,
					@@SQL_NOTES'
			)->fetch( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SET @RESTORED_UNIQUE_CHECKS = 1, @RESTORED_FOREIGN_KEY_CHECKS = "0"' );
		$driver->query( 'SET UNIQUE_CHECKS=@RESTORED_UNIQUE_CHECKS' );
		$driver->query( 'SET FOREIGN_KEY_CHECKS=@RESTORED_FOREIGN_KEY_CHECKS' );
		$this->assertSame(
			array(
				'@@UNIQUE_CHECKS'      => 1,
				'@@FOREIGN_KEY_CHECKS' => 0,
			),
			$driver->query( 'SELECT @@UNIQUE_CHECKS, @@FOREIGN_KEY_CHECKS' )->fetch( PDO::FETCH_ASSOC )
		);
	}

	public function test_session_variable_unsupported_set_forms_are_rejected(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );

		foreach (
			array(
				'SET autocommit = 2',
				'SET autocommit = -1',
				'SET autocommit = NULL',
				'SET autocommit = YES',
				'SET autocommit = (SELECT 1)',
				'SET autocommit = @saved',
				'SET GLOBAL autocommit = 1',
				'SET LOCAL autocommit = 1',
				'SET PERSIST autocommit = 1',
				'SET PERSIST_ONLY autocommit = 1',
				'SET @@GLOBAL.autocommit = 1',
				'SET @@LOCAL.autocommit = 1',
			) as $sql
		) {
			$this->assertDriverQueryRejected( $driver, $sql );
		}
	}

	public function test_user_variable_unsupported_expression_forms_are_rejected(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE real_table (id INT)' );
		$driver->query( 'SET @my_var = 1' );

		foreach (
			array(
				'SET @my_var',
				'SET @my_var = DATABASE(1)',
				'SET @my_var = @my_var * 2',
				'SELECT @my_var FROM real_table',
			) as $sql
		) {
			$this->assertDriverQueryRejected( $driver, $sql );
		}
	}

	public function test_session_variable_unsupported_select_shapes_are_rejected(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE real_table (id INT)' );

		foreach (
			array(
				'SELECT @@GLOBAL.autocommit',
				'SELECT @@LOCAL.autocommit',
				'SELECT @@autocommit FROM real_table',
			) as $sql
		) {
			$this->assertDriverQueryRejected( $driver, $sql );
		}
	}

	public function test_lock_unlock_table_statements_update_transaction_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE lock_items (id INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE lock_temp (id INT)' );

		$unlock = $driver->query( 'UNLOCK TABLES' );
		$this->assertSame( 0, $unlock->rowCount() );
		$this->assertSame( 0, $unlock->columnCount() );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertSame( array(), $driver->get_last_duckdb_queries() );

		$lock = $driver->query( 'LOCK TABLES lock_items READ' );
		$this->assertSame( 0, $lock->rowCount() );
		$this->assertSame( 0, $lock->columnCount() );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( 'BEGIN TRANSACTION', $this->lastDuckDBQuery( $driver ) );

		$unlock = $driver->query( 'UNLOCK TABLES' );
		$this->assertSame( 0, $unlock->rowCount() );
		$this->assertSame( 0, $unlock->columnCount() );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'COMMIT' ), $driver->get_last_duckdb_queries() );

		$driver->query( 'LOCK TABLES wp.lock_items WRITE' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( 'BEGIN TRANSACTION', $this->lastDuckDBQuery( $driver ) );
		$driver->query( 'UNLOCK TABLE' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );

		$driver->query( 'LOCK TABLE lock_items READ' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( 'BEGIN TRANSACTION', $this->lastDuckDBQuery( $driver ) );
		$driver->query( 'UNLOCK TABLES' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );

		$driver->query( 'LOCK TABLES lock_temp READ, lock_items WRITE' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( 'BEGIN TRANSACTION', $this->lastDuckDBQuery( $driver ) );
		$driver->query( 'UNLOCK TABLES' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );

		$driver->query( 'BEGIN' );
		$driver->query( 'INSERT INTO lock_items (id) VALUES (1)' );
		$driver->query( 'LOCK TABLES lock_items WRITE' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'COMMIT', 'BEGIN TRANSACTION' ), array_slice( $driver->get_last_duckdb_queries(), -2 ) );
		$driver->query( 'INSERT INTO lock_items (id) VALUES (2)' );
		$driver->query( 'UNLOCK TABLES' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$driver->query( 'ROLLBACK' );
		$this->assertSame(
			array(
				array( 'id' => 1 ),
				array( 'id' => 2 ),
			),
			$driver->query( 'SELECT id FROM lock_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'LOCK TABLES lock_items WRITE' );
		$driver->query( 'BEGIN' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$this->assertSame( array( 'COMMIT', 'BEGIN TRANSACTION' ), $driver->get_last_duckdb_queries() );
		$driver->query( 'COMMIT' );
		$driver->query( 'UNLOCK TABLES' );
		$this->assertFalse( $driver->get_connection()->inTransaction() );

		$driver->query( 'LOCK TABLES lock_items WRITE' );
		$driver->query( 'COMMIT' );
		$driver->query( 'BEGIN' );
		$driver->query( 'INSERT INTO lock_items (id) VALUES (4)' );
		$driver->query( 'UNLOCK TABLES' );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$driver->query( 'ROLLBACK' );
		$this->assertSame(
			array(
				array( 'id' => 1 ),
				array( 'id' => 2 ),
			),
			$driver->query( 'SELECT id FROM lock_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_lock_table_aliases_and_options_are_accepted(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE lock_items (id INT)' );
		$driver->query( 'CREATE TABLE lock_two (id INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE lock_temp (id INT)' );

		foreach (
			array(
				'LOCK TABLES lock_items AS li READ',
				'LOCK TABLES lock_items li READ',
				'LOCK TABLES lock_items READ LOCAL',
				'LOCK TABLES lock_items LOW_PRIORITY WRITE',
				'LOCK TABLES wp.lock_items AS li READ LOCAL',
				'LOCK TABLES wp.lock_items LOW_PRIORITY WRITE',
				'LOCK TABLE lock_items AS li READ LOCAL',
				'LOCK TABLE lock_items li LOW_PRIORITY WRITE',
				'LOCK TABLES lock_temp AS lt READ LOCAL, lock_items li LOW_PRIORITY WRITE, wp.lock_two AS two READ',
			) as $sql
		) {
			$lock = $driver->query( $sql );
			$this->assertSame( 0, $lock->rowCount(), 'LOCK row count mismatch for SQL: ' . $sql );
			$this->assertSame( 0, $lock->columnCount(), 'LOCK column count mismatch for SQL: ' . $sql );
			$this->assertTrue( $driver->get_connection()->inTransaction(), 'LOCK did not open a transaction for SQL: ' . $sql );
			$this->assertSame( 'BEGIN TRANSACTION', $this->lastDuckDBQuery( $driver ) );
			$driver->query( 'UNLOCK TABLES' );
			$this->assertFalse( $driver->get_connection()->inTransaction(), 'UNLOCK did not close the lock transaction for SQL: ' . $sql );
		}
	}

	public function test_lock_table_validation_matches_mysql_shaped_errors(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE lock_one (id INT)' );
		$driver->query( 'CREATE TABLE lock_three (id INT)' );
		$driver->query( 'BEGIN' );
		$driver->query( 'INSERT INTO lock_one (id) VALUES (1)' );

		try {
			$driver->query( 'LOCK TABLES lock_one READ, missing_table READ, lock_three WRITE' );
			$this->fail( 'Expected LOCK TABLES to reject a missing table.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Table 'wp.missing_table' doesn't exist", $e->getMessage() );
		}
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$driver->query( 'ROLLBACK' );
		$this->assertSame( array(), $driver->query( 'SELECT id FROM lock_one' )->fetchAll( PDO::FETCH_ASSOC ) );

		$driver->query( 'BEGIN' );
		$driver->query( 'INSERT INTO lock_one (id) VALUES (2)' );
		try {
			$driver->query( 'LOCK TABLES lock_one AS one READ LOCAL, missing_table missing LOW_PRIORITY WRITE, lock_three AS three READ' );
			$this->fail( 'Expected LOCK TABLES to reject a missing table in an aliased/optioned list.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Table 'wp.missing_table' doesn't exist", $e->getMessage() );
		}
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$driver->query( 'ROLLBACK' );
		$this->assertSame( array(), $driver->query( 'SELECT id FROM lock_one' )->fetchAll( PDO::FETCH_ASSOC ) );

		try {
			$driver->query( 'LOCK TABLES lock_one AS one READ LOCAL, missing_table missing LOW_PRIORITY WRITE, lock_three AS three READ' );
			$this->fail( 'Expected LOCK TABLES to reject a missing table in an aliased/optioned list without opening a transaction.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Table 'wp.missing_table' doesn't exist", $e->getMessage() );
		}
		$this->assertFalse( $driver->get_connection()->inTransaction() );
		$this->assertNotContains( 'BEGIN TRANSACTION', $driver->get_last_duckdb_queries() );

		foreach (
			array(
				'LOCK TABLES information_schema.tables READ' => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				'LOCK TABLES __wp_duckdb_column_metadata READ' => 'Internal DuckDB metadata tables cannot be modified',
				'LOCK TABLES __WP_DUCKDB_COLUMN_METADATA READ' => 'Internal DuckDB metadata tables cannot be modified',
				'LOCK TABLES other_database.lock_one READ' => 'Only the current database is supported',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected LOCK TABLES to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
			$this->assertFalse( $driver->get_connection()->inTransaction() );
		}
	}

	public function test_lock_table_malformed_option_order_is_rejected(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE lock_items (id INT)' );

		foreach (
			array(
				'LOCK TABLES lock_items LOW_PRIORITY READ',
				'LOCK TABLES lock_items WRITE LOCAL',
				'LOCK TABLES lock_items READ LOCAL LOW_PRIORITY',
				'LOCK TABLES lock_items LOW_PRIORITY WRITE LOCAL',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected malformed LOCK TABLES option order to be rejected: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
			$this->assertFalse( $driver->get_connection()->inTransaction(), 'Malformed LOCK TABLES opened a transaction for SQL: ' . $sql );
		}
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

		$driver->query( 'CREATE TABLE native_unique_replace_counts (email VARCHAR(100) UNIQUE, payload VARCHAR(20))' );
		$driver->query( "INSERT INTO native_unique_replace_counts (email, payload) VALUES ('a@example.com', 'old')" );

		$unique_replace = $driver->query( "REPLACE INTO native_unique_replace_counts (email, payload) VALUES ('a@example.com', 'new')" );
		$this->assertSame( 2, $unique_replace->rowCount() );

		$unique_insert = $driver->query( "REPLACE INTO native_unique_replace_counts (email, payload) VALUES ('b@example.com', 'insert')" );
		$this->assertSame( 1, $unique_insert->rowCount() );

		$this->assertSame(
			array(
				array(
					'email'   => 'a@example.com',
					'payload' => 'new',
				),
				array(
					'email'   => 'b@example.com',
					'payload' => 'insert',
				),
			),
			$driver->query( 'SELECT email, payload FROM native_unique_replace_counts ORDER BY email' )->fetchAll( PDO::FETCH_ASSOC )
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

	public function test_joined_update_rewrites_cross_join(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)" );

		$updated = $driver->query(
			"UPDATE t1 a CROSS JOIN t2 b
			SET a.note = 'cross'
			WHERE b.id = 4"
		);

		$this->assertSame( 3, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'cross',
					'only_t1' => 10,
				),
				array(
					'id'      => 2,
					'note'    => 'cross',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'cross',
					'only_t1' => 30,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
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
					'id'   => 4,
					'note' => 'b4',
					'flag' => 1,
				),
				array(
					'id'   => 5,
					'note' => 'b5',
					'flag' => 0,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$joined_update = $driver->query(
			'UPDATE t1 a CROSS JOIN t2 b ON a.id = b.id
			SET a.note = b.note
			WHERE b.flag = 1'
		);
		$this->assertSame( 2, $joined_update->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'b1',
					'only_t1' => 10,
				),
				array(
					'id'      => 2,
					'note'    => 'cross',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'b3',
					'only_t1' => 30,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_straight_join_on(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (2, 'b2', 0), (3, 'b3', 1), (4, 'b4', 1)" );

		$updated = $driver->query(
			"UPDATE t1 a STRAIGHT_JOIN t2 b ON a.id = b.id
			SET a.note = 'straight', a.only_t1 = a.only_t1 + b.flag
			WHERE b.flag = 1"
		);

		$this->assertSame( 2, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'straight',
					'only_t1' => 11,
				),
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'straight',
					'only_t1' => 31,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'note' => 'b1',
					'flag' => 1,
				),
				array(
					'id'   => 2,
					'note' => 'b2',
					'flag' => 0,
				),
				array(
					'id'   => 3,
					'note' => 'b3',
					'flag' => 1,
				),
				array(
					'id'   => 4,
					'note' => 'b4',
					'flag' => 1,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_left_and_right_join_like_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)" );

		$left_update = $driver->query(
			"UPDATE t1 a LEFT JOIN t2 b ON a.id = b.id
			SET a.note = 'left'"
		);
		$this->assertSame( 2, $left_update->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'left',
					'only_t1' => 10,
				),
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'left',
					'only_t1' => 30,
				),
				array(
					'id'      => 4,
					'note'    => 'a4',
					'only_t1' => 40,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$right_update = $driver->query(
			"UPDATE t1 a RIGHT OUTER JOIN t2 b ON a.id = b.id
			SET a.note = 'right'"
		);
		$this->assertSame( 2, $right_update->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'right',
					'only_t1' => 10,
				),
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'right',
					'only_t1' => 30,
				),
				array(
					'id'      => 4,
					'note'    => 'a4',
					'only_t1' => 40,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
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
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_natural_join_like_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)" );

		$updated = $driver->query(
			"UPDATE t1 a NATURAL JOIN t2 b
			SET a.note = 'natural'"
		);
		$this->assertSame( 4, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'natural',
					'only_t1' => 10,
				),
				array(
					'id'      => 2,
					'note'    => 'natural',
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'note'    => 'natural',
					'only_t1' => 30,
				),
				array(
					'id'      => 4,
					'note'    => 'natural',
					'only_t1' => 40,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
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
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_join_using_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, site_id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, site_id INT, replacement VARCHAR(20), flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query(
			"INSERT INTO t1 VALUES
			(1, 10, 'a1', 100),
			(2, 10, 'a2', 200),
			(3, 10, 'a3', 300),
			(1, 20, 'a4', 400)"
		);
		$driver->query(
			"INSERT INTO t2 VALUES
			(1, 10, 'u1', 'apply', 'b1'),
			(2, 10, 'u2', 'skip', 'b2'),
			(3, 10, 'u3', 'apply', 'b3'),
			(1, 20, 'u4', 'apply', 'b4'),
			(4, 10, 'u5', 'apply', 'b5')"
		);

		$target_update = $driver->query(
			"UPDATE t1 AS a
			INNER JOIN t2 AS b USING (id, site_id)
			SET a.note = 'using'
			WHERE b.id = 4 AND b.site_id = 10"
		);

		$this->assertSame( 4, $target_update->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'site_id' => 10,
					'note'    => 'using',
					'only_t1' => 100,
				),
				array(
					'id'      => 2,
					'site_id' => 10,
					'note'    => 'using',
					'only_t1' => 200,
				),
				array(
					'id'      => 3,
					'site_id' => 10,
					'note'    => 'using',
					'only_t1' => 300,
				),
				array(
					'id'      => 1,
					'site_id' => 20,
					'note'    => 'using',
					'only_t1' => 400,
				),
			),
			$driver->query( 'SELECT id, site_id, note, only_t1 FROM t1 ORDER BY site_id, id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$source_update = $driver->query(
			"UPDATE t1 a
			JOIN t2 b USING (id, site_id)
			SET b.note = 'source-using'
			WHERE a.id = 2 AND a.site_id = 10"
		);

		$this->assertSame( 5, $source_update->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'          => 1,
					'site_id'     => 10,
					'replacement' => 'u1',
					'flag'        => 'apply',
					'note'        => 'source-using',
				),
				array(
					'id'          => 2,
					'site_id'     => 10,
					'replacement' => 'u2',
					'flag'        => 'skip',
					'note'        => 'source-using',
				),
				array(
					'id'          => 3,
					'site_id'     => 10,
					'replacement' => 'u3',
					'flag'        => 'apply',
					'note'        => 'source-using',
				),
				array(
					'id'          => 4,
					'site_id'     => 10,
					'replacement' => 'u5',
					'flag'        => 'apply',
					'note'        => 'source-using',
				),
				array(
					'id'          => 1,
					'site_id'     => 20,
					'replacement' => 'u4',
					'flag'        => 'apply',
					'note'        => 'source-using',
				),
			),
			$driver->query( 'SELECT id, site_id, replacement, flag, note FROM t2 ORDER BY site_id, id' )->fetchAll( PDO::FETCH_ASSOC )
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

	public function test_joined_update_infers_non_first_writable_target(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'x'), (2, 'y'), (3, 'q')" );

		$joined = $driver->query(
			"UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET b.note = 'z'
			WHERE a.id IN (1, 3)"
		);

		$this->assertSame( 2, $joined->rowCount() );
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
					'id'   => 1,
					'note' => 'z',
				),
				array(
					'id'   => 2,
					'note' => 'y',
				),
				array(
					'id'   => 3,
					'note' => 'z',
				),
			),
			$driver->query( 'SELECT id, note FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$comma = $driver->query(
			"UPDATE t1 a, t2 b
			SET b.note = 'comma'
			WHERE a.id = b.id AND a.id = 2"
		);

		$this->assertSame( 1, $comma->rowCount() );
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
					'id'   => 1,
					'note' => 'z',
				),
				array(
					'id'   => 2,
					'note' => 'comma',
				),
				array(
					'id'   => 3,
					'note' => 'z',
				),
			),
			$driver->query( 'SELECT id, note FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_infers_same_base_non_first_alias_target(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE tree (id INT, parent_id INT, label VARCHAR(20))' );
		$driver->query( "INSERT INTO tree VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 1, 'sibling')" );

		$updated = $driver->query(
			"UPDATE tree parent JOIN tree child ON child.parent_id = parent.id
			SET child.label = 'claimed'
			WHERE parent.id = 1 AND child.id = 2"
		);

		$this->assertSame( 1, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'label' => 'root',
				),
				array(
					'id'    => 2,
					'label' => 'claimed',
				),
				array(
					'id'    => 3,
					'label' => 'sibling',
				),
			),
			$driver->query( 'SELECT id, label FROM tree ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_infers_unqualified_unique_target_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (2, 'b2', 0), (3, 'b3', 1)" );

		$updated = $driver->query(
			'UPDATE t1 JOIN t2 ON t1.id = t2.id
			SET only_t1 = 99
			WHERE t2.flag = 1'
		);

		$this->assertSame( 2, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'only_t1' => 99,
				),
				array(
					'id'      => 2,
					'only_t1' => 20,
				),
				array(
					'id'      => 3,
					'only_t1' => 99,
				),
			),
			$driver->query( 'SELECT id, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rewrites_unqualified_unique_aliased_target_column_like_sqlite(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (2, 'b2', 0), (3, 'b3', 1)" );

		$updated = $driver->query(
			'UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET only_t1 = 99
			WHERE b.flag = 1'
		);

		$this->assertSame( 3, $updated->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'a1',
					'only_t1' => 99,
				),
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 99,
				),
				array(
					'id'      => 3,
					'note'    => 'a3',
					'only_t1' => 99,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'note' => 'b1',
					'flag' => 1,
				),
				array(
					'id'   => 2,
					'note' => 'b2',
					'flag' => 0,
				),
				array(
					'id'   => 3,
					'note' => 'b3',
					'flag' => 1,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_update_rejects_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'x'), (3, 'z')" );

		$expected_t1_rows = array(
			array(
				'id'   => 1,
				'note' => 'a',
			),
			array(
				'id'   => 2,
				'note' => 'b',
			),
		);
		$expected_t2_rows = array(
			array(
				'id'   => 1,
				'note' => 'x',
			),
			array(
				'id'   => 3,
				'note' => 'z',
			),
		);

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
					'sql'     => "UPDATE t1 a CROSS JOIN t2 b SET a.note = 'target', b.note = 'source' WHERE b.id = 3",
					'message' => 'UPDATE statement modifying multiple tables is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a STRAIGHT_JOIN t2 b ON a.id = b.id SET a.note = 'target', b.note = 'source'",
					'message' => 'UPDATE statement modifying multiple tables is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING (id) SET a.note = 'target', b.note = 'source'",
					'message' => 'UPDATE statement modifying multiple tables is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a CROSS JOIN t2 b USING (id) SET a.note = 'target'",
					'message' => 'CROSS JOIN ... USING is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a STRAIGHT_JOIN t2 b SET a.note = 'target'",
					'message' => 'Expected ON in joined UPDATE statement',
				),
				array(
					'sql'     => "UPDATE t1 a STRAIGHT_JOIN t2 b USING (id) SET a.note = 'target'",
					'message' => 'STRAIGHT_JOIN ... USING is not supported',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING () SET a.note = 'target'",
					'message' => 'could not parse MySQL statement',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING (id + id) SET a.note = 'target'",
					'message' => 'could not parse MySQL statement',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING (id, id) SET a.note = 'target'",
					'message' => "Duplicate JOIN ... USING column 'id'",
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING (missing_id) SET a.note = 'target'",
					'message' => "Unknown JOIN ... USING column 'missing_id'",
				),
				array(
					'sql'     => "UPDATE t1 a JOIN (SELECT id FROM t2) b USING (id) SET a.note = 'target'",
					'message' => 'JOIN ... USING requires base table references',
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b USING (id) SET note = 'ambiguous'",
					'message' => "Ambiguous unqualified UPDATE target column 'note'",
				),
				array(
					'sql'     => "UPDATE t1 a JOIN t2 b ON a.id = b.id SET note = 'ambiguous'",
					'message' => "Ambiguous unqualified UPDATE target column 'note'",
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

			$this->assertSame(
				$expected_t1_rows,
				$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'Rejected joined UPDATE mutated t1 for SQL: ' . $rejection['sql']
			);
			$this->assertSame(
				$expected_t2_rows,
				$driver->query( 'SELECT id, note FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'Rejected joined UPDATE mutated t2 for SQL: ' . $rejection['sql']
			);
		}
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

	public function test_escaped_like_literal_predicates_are_translated_without_duckdb_runtime(): void {
		$duckdb = new class() {
			public function query( string $sql ) {
				return new class() {
					public function columnNames(): ArrayIterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
						return new ArrayIterator( array( 'matched' ) );
					}

					public function rows( bool $assoc ): array {
						return array( array( 'matched' => 1 ) );
					}
				};
			}
		};
		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => new WP_DuckDB_Connection( array( 'duckdb' => $duckdb ) ),
			)
		);

		$backslash = chr( 92 );
		$cases     = array(
			array(
				'mysql'  => "SELECT 'a{$backslash}{$backslash}aa' LIKE 'a{$backslash}{$backslash}{$backslash}{$backslash}aa' AS matched",
				'duckdb' => "SELECT 'a{$backslash}aa' LIKE 'a{$backslash}{$backslash}aa' ESCAPE '{$backslash}' AS matched",
			),
			array(
				'mysql'  => "SELECT 'a%aa' LIKE 'a{$backslash}{$backslash}%aa' AS matched",
				'duckdb' => "SELECT 'a%aa' LIKE 'a{$backslash}%aa' ESCAPE '{$backslash}' AS matched",
			),
			array(
				'mysql'  => "SELECT 'a_aa' LIKE 'a{$backslash}{$backslash}_aa' AS matched",
				'duckdb' => "SELECT 'a_aa' LIKE 'a{$backslash}_aa' ESCAPE '{$backslash}' AS matched",
			),
		);

		foreach ( $cases as $case ) {
			$driver->query( $case['mysql'] );

			$this->assertSame( array( $case['duckdb'] ), $driver->get_last_duckdb_queries() );
		}
	}

	public function test_numeric_cast_like_predicates_are_translated_without_duckdb_runtime(): void {
		$duckdb = new class() {
			public function query( string $sql ) {
				return new class() {
					public function columnNames(): ArrayIterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
						return new ArrayIterator( array( 'matched' ) );
					}

					public function rows( bool $assoc ): array {
						return array( array( 'matched' => 1 ) );
					}
				};
			}
		};
		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => new WP_DuckDB_Connection( array( 'duckdb' => $duckdb ) ),
			)
		);

		$backslash              = chr( 92 );
		$numeric_prefix_pattern = "'^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?'";
		$meta_value_numeric     = "CASE WHEN (meta_value) IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST((meta_value) AS VARCHAR), {$numeric_prefix_pattern}, 0), '') AS DOUBLE), 0) END";
		$meta_id_integer        = "CASE WHEN (meta_id) IS NULL THEN NULL ELSE CAST(trunc(COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST((meta_id) AS VARCHAR), {$numeric_prefix_pattern}, 0), '') AS DOUBLE), 0)) AS BIGINT) END";
		$cases                  = array(
			array(
				'mysql'  => "SELECT CAST(meta_value AS DECIMAL(10,2)) LIKE '10{$backslash}_%' AS matched FROM postmeta",
				'duckdb' => "SELECT CAST(({$meta_value_numeric}) AS VARCHAR) LIKE '10{$backslash}_%' ESCAPE '{$backslash}' AS matched FROM postmeta",
			),
			array(
				'mysql'  => "SELECT CAST(meta_id AS SIGNED) NOT LIKE '3' AS matched FROM postmeta",
				'duckdb' => "SELECT CAST(({$meta_id_integer}) AS VARCHAR) NOT LIKE '3' AS matched FROM postmeta",
			),
			array(
				'mysql'  => "SELECT CAST(meta_id AS UNSIGNED) LIKE '4%' ESCAPE '!' AS matched FROM postmeta",
				'duckdb' => "SELECT CAST(({$meta_id_integer}) AS VARCHAR) LIKE '4%' ESCAPE '!' AS matched FROM postmeta",
			),
		);

		foreach ( $cases as $case ) {
			$driver->query( $case['mysql'] );

			$this->assertSame( array( $case['duckdb'] ), $driver->get_last_duckdb_queries() );
		}
	}

	public function test_text_value_numeric_literal_predicates_are_translated_without_duckdb_runtime(): void {
		$duckdb = new class() {
			public function query( string $sql ) {
				return new class() {
					public function columnNames(): ArrayIterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
						return new ArrayIterator( array( 'id' ) );
					}

					public function rows( bool $assoc ): array {
						return array();
					}
				};
			}
		};
		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => new WP_DuckDB_Connection( array( 'duckdb' => $duckdb ) ),
			)
		);

		$rewrite_cases = array(
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value < 1780093652 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT pm.id FROM postmeta pm WHERE pm.meta_value < 1780093652 ORDER BY pm.id',
				'duckdb' => 'SELECT pm.id FROM postmeta pm WHERE CAST("pm"."meta_value" AS VARCHAR) < CAST(1780093652 AS VARCHAR) ORDER BY pm.id',
			),
			array(
				'mysql'  => 'SELECT b.id FROM options b WHERE b.option_value < 1782556962 ORDER BY b.id',
				'duckdb' => 'SELECT b.id FROM options b WHERE CAST("b"."option_value" AS VARCHAR) < CAST(1782556962 AS VARCHAR) ORDER BY b.id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE 1780093652 > meta_value ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST(1780093652 AS VARCHAR) > CAST("meta_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value = 10.50 ORDER BY id',
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) = '10.5' ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE -6 < meta_value ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST(-6 AS VARCHAR) < CAST("meta_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE +7 = meta_value ORDER BY id',
				'duckdb' => "SELECT id FROM postmeta WHERE '7' = CAST(\"meta_value\" AS VARCHAR) ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value <> 10 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) <> CAST(10 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE 10 != meta_value ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST(10 AS VARCHAR) != CAST("meta_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value BETWEEN 10 AND 11 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND CAST(11 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value BETWEEN 10 AND 'abc' ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND 'abc' ORDER BY id",
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value BETWEEN '10' AND 11 ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) BETWEEN '10' AND CAST(11 AS VARCHAR) ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value BETWEEN 10.00 AND 10.90 ORDER BY id',
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) BETWEEN '10.0' AND '10.9' ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value NOT BETWEEN 10 AND 11 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND CAST(11 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value NOT BETWEEN 10 AND 'abc' ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) NOT BETWEEN CAST(10 AS VARCHAR) AND 'abc' ORDER BY id",
			),
			array(
				'mysql'  => "SELECT id FROM options WHERE option_value BETWEEN 10 AND 'abc' ORDER BY id",
				'duckdb' => "SELECT id FROM options WHERE CAST(\"option_value\" AS VARCHAR) BETWEEN CAST(10 AS VARCHAR) AND 'abc' ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value IN (10, 11) ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) IN (CAST(10 AS VARCHAR), CAST(11 AS VARCHAR)) ORDER BY id',
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value IN (10 + 1, 'abc') ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) IN (CAST(10 + 1 AS VARCHAR), 'abc') ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value IN (10.50 + 0, +3 * 1) ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) IN (CAST(CAST(10.50 + 0 AS DOUBLE) AS VARCHAR), CAST(+3 * 1 AS VARCHAR)) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value IN (-7, +7, 10.50) ORDER BY id',
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) IN (CAST(-7 AS VARCHAR), '7', '10.5') ORDER BY id",
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value IN (10, NULL, 'abc', 10.50) ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) IN (CAST(10 AS VARCHAR), NULL, 'abc', '10.5') ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value NOT IN (10, 11) ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), CAST(11 AS VARCHAR)) ORDER BY id',
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value NOT IN (10 + 1, 'abc') ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) NOT IN (CAST(10 + 1 AS VARCHAR), 'abc') ORDER BY id",
			),
			array(
				'mysql'  => "SELECT id FROM postmeta WHERE meta_value NOT IN (10, NULL, 'abc') ORDER BY id",
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) NOT IN (CAST(10 AS VARCHAR), NULL, 'abc') ORDER BY id",
			),
			array(
				'mysql'  => "SELECT id FROM options WHERE option_value IN (10, NULL, '10abc') ORDER BY id",
				'duckdb' => "SELECT id FROM options WHERE CAST(\"option_value\" AS VARCHAR) IN (CAST(10 AS VARCHAR), NULL, '10abc') ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value LIKE 10 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) LIKE CAST(10 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value NOT LIKE 10 ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST("meta_value" AS VARCHAR) NOT LIKE CAST(10 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE 10 LIKE meta_value ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST(10 AS VARCHAR) LIKE CAST("meta_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE 10 NOT LIKE meta_value ORDER BY id',
				'duckdb' => 'SELECT id FROM postmeta WHERE CAST(10 AS VARCHAR) NOT LIKE CAST("meta_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT pm.id FROM postmeta pm WHERE pm.meta_value LIKE 10 ORDER BY pm.id',
				'duckdb' => 'SELECT pm.id FROM postmeta pm WHERE CAST("pm"."meta_value" AS VARCHAR) LIKE CAST(10 AS VARCHAR) ORDER BY pm.id',
			),
			array(
				'mysql'  => 'SELECT pm.id FROM postmeta pm WHERE 10 LIKE pm.meta_value ORDER BY pm.id',
				'duckdb' => 'SELECT pm.id FROM postmeta pm WHERE CAST(10 AS VARCHAR) LIKE CAST("pm"."meta_value" AS VARCHAR) ORDER BY pm.id',
			),
			array(
				'mysql'  => 'SELECT id FROM options WHERE option_value LIKE 10 ORDER BY id',
				'duckdb' => 'SELECT id FROM options WHERE CAST("option_value" AS VARCHAR) LIKE CAST(10 AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM options WHERE 10 LIKE option_value ORDER BY id',
				'duckdb' => 'SELECT id FROM options WHERE CAST(10 AS VARCHAR) LIKE CAST("option_value" AS VARCHAR) ORDER BY id',
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta WHERE meta_value LIKE 10.50 ORDER BY id',
				'duckdb' => "SELECT id FROM postmeta WHERE CAST(\"meta_value\" AS VARCHAR) LIKE '10.5' ORDER BY id",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta ORDER BY meta_value + 0',
				'duckdb' => "SELECT id FROM postmeta ORDER BY CASE WHEN \"meta_value\" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST(\"meta_value\" AS VARCHAR), '^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?', 0), '') AS DOUBLE), 0) END + 0 NULLS FIRST",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta ORDER BY meta_value + 0 ASC, id',
				'duckdb' => "SELECT id FROM postmeta ORDER BY CASE WHEN \"meta_value\" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST(\"meta_value\" AS VARCHAR), '^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?', 0), '') AS DOUBLE), 0) END + 0 ASC NULLS FIRST, id",
			),
			array(
				'mysql'  => 'SELECT pm.id FROM postmeta pm ORDER BY pm.meta_value + 0 ASC, pm.id',
				'duckdb' => "SELECT pm.id FROM postmeta pm ORDER BY CASE WHEN \"pm\".\"meta_value\" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST(\"pm\".\"meta_value\" AS VARCHAR), '^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?', 0), '') AS DOUBLE), 0) END + 0 ASC NULLS FIRST, pm.id",
			),
			array(
				'mysql'  => 'SELECT id FROM options ORDER BY option_value + 0',
				'duckdb' => "SELECT id FROM options ORDER BY CASE WHEN \"option_value\" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST(\"option_value\" AS VARCHAR), '^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?', 0), '') AS DOUBLE), 0) END + 0 NULLS FIRST",
			),
			array(
				'mysql'  => 'SELECT id FROM postmeta ORDER BY meta_value + 0 DESC',
				'duckdb' => "SELECT id FROM postmeta ORDER BY CASE WHEN \"meta_value\" IS NULL THEN NULL ELSE COALESCE(TRY_CAST(NULLIF(regexp_extract(CAST(\"meta_value\" AS VARCHAR), '^\\s*[+-]?(?:(?:[0-9]+(?:\\.[0-9]*)?)|(?:\\.[0-9]+))(?:[eE][+-]?[0-9]+)?', 0), '') AS DOUBLE), 0) END + 0 DESC",
			),
			array(
				'mysql'  => 'SELECT ID FROM users WHERE ID = \'yololololo\'',
				'duckdb' => "SELECT ID FROM users WHERE CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('yololololo' AS DOUBLE) END",
			),
			array(
				'mysql'  => 'SELECT ID FROM users WHERE \'12abc\' = users.ID',
				'duckdb' => "SELECT ID FROM users WHERE CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('12abc' AS DOUBLE) IS NULL THEN FALSE ELSE TRY_CAST('12abc' AS DOUBLE) = \"users\".\"ID\" END",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID BETWEEN '1' AND 'bad'",
				'duckdb' => "SELECT ID FROM users WHERE (CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('1' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" >= TRY_CAST('1' AS DOUBLE) END AND CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN TRUE ELSE \"ID\" <= TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE users.ID NOT BETWEEN '1' AND 'bad'",
				'duckdb' => "SELECT ID FROM users WHERE (CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('1' AS DOUBLE) IS NULL THEN TRUE ELSE \"users\".\"ID\" < TRY_CAST('1' AS DOUBLE) END OR CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN FALSE ELSE \"users\".\"ID\" > TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID IN ('1', 'yololololo', '003')",
				'duckdb' => "SELECT ID FROM users WHERE (CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('1' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('1' AS DOUBLE) END OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('yololololo' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('yololololo' AS DOUBLE) END OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('003' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('003' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID IN (1, 'bad', 3)",
				'duckdb' => "SELECT ID FROM users WHERE (\"ID\" = 1 OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('bad' AS DOUBLE) END OR \"ID\" = 3)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID IN (1 + 2, 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (\"ID\" = 1 + 2 OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID IN (+1, -3, 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (\"ID\" = +1 OR \"ID\" = -3 OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID IN (1, NULL, 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (\"ID\" = 1 OR NULL OR CASE WHEN \"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN FALSE ELSE \"ID\" = TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE users.ID NOT IN ('1', 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('1' AS DOUBLE) IS NULL THEN TRUE ELSE \"users\".\"ID\" <> TRY_CAST('1' AS DOUBLE) END AND CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN TRUE ELSE \"users\".\"ID\" <> TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE users.ID NOT IN (1, NULL, 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (\"users\".\"ID\" <> 1 AND NULL AND CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN TRUE ELSE \"users\".\"ID\" <> TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE users.ID NOT IN (2 * 3, 'bad')",
				'duckdb' => "SELECT ID FROM users WHERE (\"users\".\"ID\" <> 2 * 3 AND CASE WHEN \"users\".\"ID\" IS NULL THEN NULL WHEN TRY_CAST('bad' AS DOUBLE) IS NULL THEN TRUE ELSE \"users\".\"ID\" <> TRY_CAST('bad' AS DOUBLE) END)",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID LIKE '1%'",
				'duckdb' => "SELECT ID FROM users WHERE CAST(\"ID\" AS VARCHAR) LIKE '1%'",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE users.ID NOT LIKE '1%'",
				'duckdb' => "SELECT ID FROM users WHERE CAST(\"users\".\"ID\" AS VARCHAR) NOT LIKE '1%'",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID LIKE '1!%' ESCAPE '!'",
				'duckdb' => "SELECT ID FROM users WHERE CAST(\"ID\" AS VARCHAR) LIKE '1!%' ESCAPE '!'",
			),
			array(
				'mysql'  => "SELECT ID FROM users WHERE ID LIKE '1\\_%'",
				'duckdb' => "SELECT ID FROM users WHERE CAST(\"ID\" AS VARCHAR) LIKE '1\\_%' ESCAPE '\\'",
			),
			array(
				'mysql'  => "SELECT meta_id FROM postmeta WHERE post_id LIKE '1%' ORDER BY meta_id",
				'duckdb' => "SELECT meta_id FROM postmeta WHERE CAST(\"post_id\" AS VARCHAR) LIKE '1%' ORDER BY meta_id",
			),
		);

		foreach ( $rewrite_cases as $case ) {
			$driver->query( $case['mysql'] );

			$this->assertSame( $case['duckdb'], $this->lastDuckDBQuery( $driver ) );
		}

		foreach (
			array(
				'SELECT id FROM postmeta WHERE title < 1780093652 ORDER BY id',
				"SELECT id FROM postmeta WHERE meta_value < '1780093652' ORDER BY id",
				'SELECT id FROM postmeta WHERE meta_value < 1780093652.5 ORDER BY id',
				"SELECT id FROM plugin_items WHERE id < '10' ORDER BY id",
				"SELECT id FROM plugin_items WHERE parent != 'abc' ORDER BY id",
				"SELECT plugin_items.count FROM plugin_items WHERE plugin_items.count < '10' ORDER BY plugin_items.count",
				"SELECT id FROM postmeta WHERE meta_value IN (10 + title, 'abc') ORDER BY id",
				"SELECT id FROM postmeta WHERE meta_value IN (10 + 1 + 2, 'abc') ORDER BY id",
				"SELECT id FROM postmeta WHERE meta_value IN (ABS(10), 'abc') ORDER BY id",
				"SELECT id FROM postmeta WHERE meta_value IN ((SELECT 11), 'abc') ORDER BY id",
				"SELECT id FROM postmeta WHERE meta_value BETWEEN 10 AND 'abc' + 1 ORDER BY id",
				"SELECT ID FROM users WHERE ID BETWEEN 1 AND 'bad'",
				"SELECT ID FROM users WHERE ID BETWEEN '1' AND 'bad' + 1",
				"SELECT ID FROM users WHERE users.ID NOT BETWEEN '1' AND 'bad' + 1",
				"SELECT ID FROM users WHERE ID IN (1 + post_parent, 'bad')",
				"SELECT ID FROM users WHERE ID IN (1 + 2 + 3, 'bad')",
				"SELECT ID FROM users WHERE ID IN (ABS(1), 'bad')",
				"SELECT ID FROM users WHERE user_login IN ('1', 'bad')",
				'SELECT ID FROM users WHERE ID LIKE 1',
				"SELECT id FROM plugin_items WHERE id LIKE '1%' ORDER BY id",
				'SELECT id FROM postmeta WHERE 10 LIKE title ORDER BY id',
				'SELECT id FROM postmeta WHERE 10 LIKE meta_value ESCAPE 1 ORDER BY id',
				"SELECT id FROM postmeta WHERE 10 LIKE meta_value ESCAPE '!' ORDER BY id",
			) as $sql
		) {
			$driver->query( $sql );

			$this->assertStringNotContainsString( 'TRY_CAST(', $this->lastDuckDBQuery( $driver ), $sql );
		}

		$driver->query( "SELECT id FROM postmeta WHERE meta_value IN (10 + title, 'abc') ORDER BY id" );
		$this->assertStringNotContainsString( 'CAST("meta_value" AS VARCHAR) IN', $this->lastDuckDBQuery( $driver ) );

		$driver->query( "SELECT id FROM plugin_items WHERE id LIKE '1%' ORDER BY id" );
		$this->assertStringNotContainsString( 'CAST("id" AS VARCHAR) LIKE', $this->lastDuckDBQuery( $driver ) );

		$driver->query( 'SELECT id FROM postmeta WHERE title LIKE 10 ORDER BY id' );
		$this->assertStringNotContainsString( 'CAST("title" AS VARCHAR) LIKE', $this->lastDuckDBQuery( $driver ) );

		$driver->query( 'SELECT id FROM postmeta ORDER BY title + 0' );
		$this->assertStringNotContainsString( 'NULLS FIRST', $this->lastDuckDBQuery( $driver ) );

		$driver->query( 'SELECT id FROM postmeta WHERE meta_value LIKE 10 ESCAPE 1 ORDER BY id' );
		$this->assertStringNotContainsString( 'CAST("meta_value" AS VARCHAR) LIKE', $this->lastDuckDBQuery( $driver ) );

		$driver->query( 'SELECT id FROM postmeta WHERE 10 LIKE meta_value ESCAPE 1 ORDER BY id' );
		$this->assertStringNotContainsString( 'CAST(10 AS VARCHAR) LIKE CAST("meta_value" AS VARCHAR)', $this->lastDuckDBQuery( $driver ) );

		$driver->query( "SELECT id FROM postmeta WHERE 10 LIKE meta_value ESCAPE '!' ORDER BY id" );
		$this->assertStringNotContainsString( 'CAST(10 AS VARCHAR) LIKE CAST("meta_value" AS VARCHAR)', $this->lastDuckDBQuery( $driver ) );

		$driver->query( "SELECT ID FROM users WHERE ID LIKE '1%' ESCAPE 1" );
		$this->assertStringNotContainsString( 'CAST("ID" AS VARCHAR) LIKE', $this->lastDuckDBQuery( $driver ) );
	}

	public function test_escaped_like_literal_predicates_use_mysql_backslash_semantics(): void {
		$this->requireDuckDBRuntime();

		$driver    = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$backslash = chr( 92 );
		$cases     = array(
			'literal backslash matches itself'   => array(
				"SELECT 'a{$backslash}{$backslash}aa' LIKE 'a{$backslash}{$backslash}{$backslash}{$backslash}aa' AS matched",
				true,
			),
			'literal percent matches itself'     => array(
				"SELECT 'a%aa' LIKE 'a{$backslash}{$backslash}%aa' AS matched",
				true,
			),
			'literal percent is not wildcard'    => array(
				"SELECT 'aaaa' LIKE 'a{$backslash}{$backslash}%aa' AS matched",
				false,
			),
			'literal underscore matches itself'  => array(
				"SELECT 'a_aa' LIKE 'a{$backslash}{$backslash}_aa' AS matched",
				true,
			),
			'literal underscore is not wildcard' => array(
				"SELECT 'aaaa' LIKE 'a{$backslash}{$backslash}_aa' AS matched",
				false,
			),
		);

		foreach ( $cases as $message => $case ) {
			$row = $driver->query( $case[0] )->fetch( PDO::FETCH_ASSOC );

			$this->assertSame( $case[1], (bool) $row['matched'], $message );
			$this->assertStringContainsString( " ESCAPE '\\'", $this->lastDuckDBQuery( $driver ), $message );
		}
	}

	public function test_sql_calc_found_rows_and_found_rows_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_found_rows_users (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_login VARCHAR(60) NOT NULL DEFAULT '',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_found_rows_users (user_login) VALUES
			('ada'),
			('grace'),
			('katherine')"
		);

		$result = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS ID, user_login FROM wp_found_rows_users ORDER BY ID LIMIT 2'
		);

		$this->assertSame( 0, $result->rowCount() );
		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'ID', $id_meta['name'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_found_rows_users', $id_meta['mysqli:orgtable'] );
		$this->assertSame( 20, $id_meta['len'] );
		$this->assertSame( 8, $id_meta['mysqli:type'] );

		$login_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'user_login', $login_meta['name'] );
		$this->assertSame( 'user_login', $login_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_found_rows_users', $login_meta['mysqli:orgtable'] );
		$this->assertSame( 240, $login_meta['len'] );
		$this->assertSame( 253, $login_meta['mysqli:type'] );

		$rows = $result->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'user_login' => 'ada',
				),
				array(
					'ID'         => 2,
					'user_login' => 'grace',
				),
			),
			$rows
		);
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_found_rows_state_tracks_selects_and_result_counts(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE wp_found_rows_state (id INT, label VARCHAR(20))' );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "INSERT INTO wp_found_rows_state VALUES (1, 'one'), (2, 'two'), (3, 'three')" );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$plain = $driver->query( 'SELECT id, label FROM wp_found_rows_state ORDER BY id LIMIT 2' );
		$this->assertSame( 0, $plain->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'label' => 'one',
				),
				array(
					'id'    => 2,
					'label' => 'two',
				),
			),
			$plain->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "UPDATE wp_found_rows_state SET label = 'updated' WHERE id = 1" );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SELECT id, label FROM wp_found_rows_state ORDER BY id' );
		$driver->query( 'CREATE TABLE wp_found_rows_state_extra (id INT)' );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SHOW TABLES' );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DESCRIBE wp_found_rows_state' );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_failed_selects_reset_found_rows_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE wp_found_rows_reset (id INT)' );
		$driver->query( 'INSERT INTO wp_found_rows_reset VALUES (1), (2), (3)' );

		$driver->query( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		try {
			$driver->query( 'SELECT * FROM missing_found_rows_reset' );
			$this->fail( 'Missing table SELECT should have failed.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'missing_found_rows_reset', $e->getMessage() );
		}
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		try {
			$driver->query( 'SELECT SQL_CALC_FOUND_ROWS * FROM missing_found_rows_reset LIMIT 1' );
			$this->fail( 'Missing table SQL_CALC_FOUND_ROWS SELECT should have failed.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'missing_found_rows_reset', $e->getMessage() );
		}
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_select_index_hints_are_ignored(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_hint_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				post_title VARCHAR(200) NOT NULL DEFAULT '',
				PRIMARY KEY (ID),
				KEY post_status (post_status)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_hint_posts (post_status, post_title) VALUES
			('publish', 'first'),
			('draft', 'second'),
			('publish', 'third')"
		);

		$this->assertSame(
			array(
				array( 'post_title' => 'first' ),
				array( 'post_title' => 'third' ),
			),
			$driver->query(
				"SELECT post_title
				FROM wp_hint_posts FORCE INDEX (PRIMARY, post_status)
				WHERE post_status = 'publish'
				ORDER BY ID"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'post_status' => 'draft',
					'total'       => 1,
				),
				array(
					'post_status' => 'publish',
					'total'       => 2,
				),
			),
			$driver->query(
				'SELECT post_status, COUNT(*) AS total
				FROM wp_hint_posts USE KEY FOR GROUP BY (post_status)
				GROUP BY post_status
				ORDER BY post_status'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'post_title' => 'third' ) ),
			$driver->query(
				'SELECT post_title
				FROM wp_hint_posts IGNORE INDEX FOR ORDER BY (post_status)
				ORDER BY ID DESC
				LIMIT 1'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_row_locking_clauses_are_ignored_for_selects(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE wp_lock_items (name VARCHAR(255), value VARCHAR(255))' );
		$driver->query( "INSERT INTO wp_lock_items (name, value) VALUES ('test_lock', '123')" );

		foreach (
			array(
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR SHARE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' LOCK IN SHARE MODE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE SKIP LOCKED",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE NOWAIT",
			) as $sql
		) {
			$this->assertSame(
				array( array( 'value' => '123' ) ),
				$driver->query( $sql )->fetchAll( PDO::FETCH_ASSOC ),
				'Row-locking clause changed SELECT results for SQL: ' . $sql
			);
		}
	}

	public function test_order_by_field_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_field_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_field_options (option_name, option_value) VALUES
			('User 0000019', 'second'),
			('User 0000020', 'third'),
			('User 0000018', 'first')"
		);

		$this->assertSame(
			array(
				array( 'sorting_order' => 1 ),
				array( 'sorting_order' => 2 ),
				array( 'sorting_order' => 3 ),
			),
			$driver->query(
				"SELECT FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020') AS sorting_order
				FROM wp_field_options
				ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array( 'option_value' => 'first' ),
				array( 'option_value' => 'second' ),
				array( 'option_value' => 'third' ),
			),
			$driver->query(
				"SELECT option_value
				FROM wp_field_options
				ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'case_match' => 2,
					'null_match' => 0,
					'no_match'   => 0,
				),
			),
			$driver->query(
				"SELECT FIELD('b', 'A', 'B') AS case_match,
				FIELD(NULL, 'A', 'B') AS null_match,
				FIELD('z', 'A', 'B') AS no_match"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
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

	public function test_multi_table_delete_removes_expired_transient_alias_rows_with_literal_timeout(): void {
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
			('rss_1', 'rss', 'yes')"
		);

		$delete         = $driver->query(
			"DELETE a, b FROM wp_options a, wp_options b
			WHERE a.option_name LIKE '\_transient\_%'
			AND a.option_name NOT LIKE '\_transient\_timeout_%'
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < 1782556962"
		);
		$delete_queries = $driver->get_last_duckdb_queries();

		$uses_text_affinity_cast = false;
		foreach ( $delete_queries as $query ) {
			if ( false !== strpos( $query, 'CAST("b"."option_value" AS VARCHAR) < CAST(1782556962 AS VARCHAR)' ) ) {
				$uses_text_affinity_cast = true;
				break;
			}
		}

		$this->assertSame( 2, $delete->rowCount() );
		$this->assertTrue( $uses_text_affinity_cast );
		$this->assertSame(
			array(
				array( 'option_name' => '_transient_tag5' ),
				array( 'option_name' => '_transient_timeout_tag5' ),
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

	public function test_joined_delete_rewrites_join_using_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')" );

		$single_target_delete = $driver->query(
			"DELETE a FROM t1 a
			JOIN t2 b USING (id)
			WHERE b.flag = 'drop'"
		);

		$this->assertSame( 2, $single_target_delete->rowCount() );
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
					'id'   => 1,
					'flag' => 'drop',
				),
				array(
					'id'   => 3,
					'flag' => 'drop',
				),
				array(
					'id'   => 4,
					'flag' => 'keep',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')" );

		$multi_target_delete = $driver->query(
			"DELETE a, b FROM t1 a
			JOIN t2 b USING (id)
			WHERE b.flag = 'drop'"
		);

		$this->assertSame( 4, $multi_target_delete->rowCount() );
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
					'id'   => 4,
					'flag' => 'keep',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_delete_rewrites_cross_join_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)" );

		$single_target_delete = $driver->query(
			'DELETE a FROM t1 a CROSS JOIN t2 b
			WHERE a.id = 2 AND b.id = 4'
		);
		$this->assertSame( 1, $single_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'note'    => 'a1',
					'only_t1' => 10,
				),
				array(
					'id'      => 3,
					'note'    => 'a3',
					'only_t1' => 30,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$multi_target_delete = $driver->query(
			'DELETE a, b FROM t1 a CROSS JOIN t2 b
			WHERE a.id = 1 AND b.id = 4'
		);
		$this->assertSame( 2, $multi_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 3,
					'note'    => 'a3',
					'only_t1' => 30,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
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
					'flag' => 0,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$using_form_delete = $driver->query(
			'DELETE FROM a USING t1 a CROSS JOIN t2 b
			WHERE a.id = 3 AND b.id = 5'
		);
		$this->assertSame( 1, $using_form_delete->rowCount() );
		$this->assertSame(
			array(),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
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
					'flag' => 0,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_delete_rewrites_cross_join_on_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)" );

		$single_target_delete = $driver->query(
			'DELETE a FROM t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE b.flag = 1 AND a.id = 1'
		);
		$this->assertSame( 1, $single_target_delete->rowCount() );
		$this->assertSame(
			array(
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
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$multi_target_delete = $driver->query(
			'DELETE a, b FROM t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE a.id = 3'
		);
		$this->assertSame( 2, $multi_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 20,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'note' => 'b1',
					'flag' => 1,
				),
				array(
					'id'   => 4,
					'note' => 'b4',
					'flag' => 1,
				),
				array(
					'id'   => 5,
					'note' => 'b5',
					'flag' => 0,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "INSERT INTO t1 VALUES (6, 'a6', 60)" );
		$driver->query( "INSERT INTO t2 VALUES (6, 'b6', 1)" );
		$using_form_delete = $driver->query(
			'DELETE FROM a USING t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE b.id = 6'
		);
		$this->assertSame( 1, $using_form_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'      => 2,
					'note'    => 'a2',
					'only_t1' => 20,
				),
			),
			$driver->query( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'note' => 'b1',
					'flag' => 1,
				),
				array(
					'id'   => 4,
					'note' => 'b4',
					'flag' => 1,
				),
				array(
					'id'   => 5,
					'note' => 'b5',
					'flag' => 0,
				),
				array(
					'id'   => 6,
					'note' => 'b6',
					'flag' => 1,
				),
			),
			$driver->query( 'SELECT id, note, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_delete_accepts_target_wildcard_aliases(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')" );
		$driver->query( "INSERT INTO t2 VALUES (10, 1, 'drop'), (11, 3, 'drop'), (12, 4, 'keep')" );

		$single_target_delete = $driver->query(
			"DELETE a.* FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop'"
		);

		$this->assertSame( 2, $single_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'note' => 'b',
				),
				array(
					'id'   => 4,
					'note' => 'd',
				),
			),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'        => 10,
					'target_id' => 1,
					'flag'      => 'drop',
				),
				array(
					'id'        => 11,
					'target_id' => 3,
					'flag'      => 'drop',
				),
				array(
					'id'        => 12,
					'target_id' => 4,
					'flag'      => 'keep',
				),
			),
			$driver->query( 'SELECT id, target_id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (10, 1, 'drop'), (11, 3, 'drop'), (12, 2, 'keep')" );

		$multi_target_delete = $driver->query(
			"DELETE a.*, b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop'"
		);

		$this->assertSame( 4, $multi_target_delete->rowCount() );
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
					'id'        => 12,
					'target_id' => 2,
					'flag'      => 'keep',
				),
			),
			$driver->query( 'SELECT id, target_id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_delete_accepts_derived_read_only_sources(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')" );

		$single_target_delete = $driver->query(
			"DELETE a FROM t1 a
			JOIN (SELECT id FROM t2 WHERE flag = 'drop') b ON a.id = b.id"
		);

		$this->assertSame( 2, $single_target_delete->rowCount() );
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
					'id'   => 1,
					'flag' => 'drop',
				),
				array(
					'id'   => 3,
					'flag' => 'drop',
				),
				array(
					'id'   => 4,
					'flag' => 'keep',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop'), (2, 'keep'), (3, 'drop')" );

		$comma_source_delete = $driver->query(
			"DELETE a FROM t1 a, (SELECT id FROM t2 WHERE flag = 'drop') b
			WHERE a.id = b.id"
		);

		$this->assertSame( 2, $comma_source_delete->rowCount() );
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
					'id'   => 1,
					'flag' => 'drop',
				),
				array(
					'id'   => 2,
					'flag' => 'keep',
				),
				array(
					'id'   => 3,
					'flag' => 'drop',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t3 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')" );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop'), (3, 'drop'), (4, 'keep')" );
		$driver->query( "INSERT INTO t3 VALUES (1, 'x'), (3, 'z'), (4, 'other')" );

		$multi_target_delete = $driver->query(
			"DELETE a, c FROM t1 a
			JOIN t3 c ON c.id = a.id
			JOIN (SELECT id FROM t2 WHERE flag = 'drop') b ON b.id = a.id"
		);

		$this->assertSame( 4, $multi_target_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'note' => 'b',
				),
				array(
					'id'   => 4,
					'note' => 'd',
				),
			),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'flag' => 'drop',
				),
				array(
					'id'   => 3,
					'flag' => 'drop',
				),
				array(
					'id'   => 4,
					'flag' => 'keep',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 4,
					'note' => 'other',
				),
			),
			$driver->query( 'SELECT id, note FROM t3 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t2 (id INT, flag VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t3 (id INT, note VARCHAR(20))' );
		$driver->query( "INSERT INTO t2 VALUES (1, 'drop'), (2, 'keep'), (3, 'drop')" );
		$driver->query( "INSERT INTO t3 VALUES (1, 'x'), (2, 'y'), (3, 'z')" );

		$target_not_first_delete = $driver->query(
			"DELETE c FROM (SELECT id FROM t2 WHERE flag = 'drop') b
			JOIN t3 c ON c.id = b.id"
		);

		$this->assertSame( 2, $target_not_first_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'note' => 'y',
				),
			),
			$driver->query( 'SELECT id, note FROM t3 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'flag' => 'drop',
				),
				array(
					'id'   => 2,
					'flag' => 'keep',
				),
				array(
					'id'   => 3,
					'flag' => 'drop',
				),
			),
			$driver->query( 'SELECT id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_single_target_joined_delete_rewrites_join_using_and_alias_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f')" );
		$driver->query(
			"INSERT INTO t2 VALUES
			(10, 1, 'drop', 'x'),
			(11, 1, 'drop', 'duplicate'),
			(12, 2, 'keep', 'y'),
			(13, 3, 'drop', 'z'),
			(14, 4, 'drop', 'w'),
			(15, 5, 'source', 's'),
			(16, 6, 'source', 'q')"
		);

		$duplicate_match = $driver->query(
			"DELETE a FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertSame( 1, $duplicate_match->rowCount() );

		$using_delete = $driver->query(
			"DELETE FROM a USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertSame( 1, $using_delete->rowCount() );

		$target_not_first = $driver->query(
			"DELETE b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE a.id = 5 AND b.flag = 'source'"
		);
		$this->assertSame( 1, $target_not_first->rowCount() );

		$table_name_target = $driver->query(
			"DELETE t1 FROM t1
			JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertSame( 1, $table_name_target->rowCount() );

		$alias_only = $driver->query( 'DELETE a FROM t1 a WHERE a.id = 6' );
		$this->assertSame( 1, $alias_only->rowCount() );

		$same_base_aliases = $driver->query(
			'DELETE child FROM t1 parent
			JOIN t1 child ON child.id = 5
			WHERE parent.id = 2'
		);
		$this->assertSame( 1, $same_base_aliases->rowCount() );

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
					'id'        => 10,
					'target_id' => 1,
					'flag'      => 'drop',
				),
				array(
					'id'        => 11,
					'target_id' => 1,
					'flag'      => 'drop',
				),
				array(
					'id'        => 12,
					'target_id' => 2,
					'flag'      => 'keep',
				),
				array(
					'id'        => 13,
					'target_id' => 3,
					'flag'      => 'drop',
				),
				array(
					'id'        => 14,
					'target_id' => 4,
					'flag'      => 'drop',
				),
				array(
					'id'        => 16,
					'target_id' => 6,
					'flag'      => 'source',
				),
			),
			$driver->query( 'SELECT id, target_id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_multi_target_joined_delete_rewrites_join_using_and_alias_forms(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT, note VARCHAR(20))' );
		$driver->query( 'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))' );
		$driver->query( "INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f'), (7, 'g')" );
		$driver->query(
			"INSERT INTO t2 VALUES
			(10, 1, 'drop', 'x'),
			(11, 1, 'drop', 'duplicate'),
			(12, 2, 'keep', 'y'),
			(13, 3, 'drop', 'z'),
			(14, 4, 'drop', 'w'),
			(15, 5, 'drop', 'as-alias'),
			(16, 6, 'drop', 'same-table'),
			(17, 7, 'keep', 'survivor')"
		);

		$duplicate_match = $driver->query(
			"DELETE a, b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertSame( 3, $duplicate_match->rowCount() );

		$using_delete = $driver->query(
			"DELETE FROM a, b USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertSame( 2, $using_delete->rowCount() );

		$table_name_target = $driver->query(
			"DELETE t1, t2 FROM t1
			INNER JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertSame( 2, $table_name_target->rowCount() );

		$as_alias_target = $driver->query(
			"DELETE a, b FROM t1 AS a
			JOIN t2 AS b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 5"
		);
		$this->assertSame( 2, $as_alias_target->rowCount() );

		$same_base_aliases = $driver->query(
			'DELETE parent, child FROM t1 parent
			JOIN t1 child ON child.id = 6
			WHERE parent.id = 2'
		);
		$this->assertSame( 2, $same_base_aliases->rowCount() );

		$same_base_overlap = $driver->query(
			'DELETE left_alias, right_alias FROM t1 left_alias
			JOIN t1 right_alias ON right_alias.id = left_alias.id
			WHERE left_alias.id = 7'
		);
		$this->assertSame( 1, $same_base_overlap->rowCount() );

		$this->assertSame(
			array(),
			$driver->query( 'SELECT id, note FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'        => 12,
					'target_id' => 2,
					'flag'      => 'keep',
				),
				array(
					'id'        => 16,
					'target_id' => 6,
					'flag'      => 'drop',
				),
				array(
					'id'        => 17,
					'target_id' => 7,
					'flag'      => 'keep',
				),
			),
			$driver->query( 'SELECT id, target_id, flag FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_joined_delete_accepts_information_schema_tables_read_only_source(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE info_delete_items (id INT, value VARCHAR(64))' );
		$driver->query(
			"INSERT INTO info_delete_items VALUES
			(1, 'info_delete_items'),
			(2, 'other'),
			(3, 'info_delete_items'),
			(4, 'info_delete_items')"
		);

		$joined_delete = $driver->query(
			"DELETE d FROM info_delete_items d
			JOIN information_schema.tables it ON d.value = it.table_name
			WHERE it.table_schema = 'wp' AND d.id < 4"
		);
		$this->assertSame( 2, $joined_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'    => 2,
					'value' => 'other',
				),
				array(
					'id'    => 4,
					'value' => 'info_delete_items',
				),
			),
			$driver->query( 'SELECT id, value FROM info_delete_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$comma_delete = $driver->query(
			"DELETE d FROM info_delete_items d, information_schema.tables it
			WHERE d.value = it.table_name AND it.table_schema = 'wp'"
		);
		$this->assertSame( 1, $comma_delete->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'    => 2,
					'value' => 'other',
				),
			),
			$driver->query( 'SELECT id, value FROM info_delete_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_multi_table_delete_rejects_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE t1 (id INT)' );
		$driver->query( 'CREATE TABLE t2 (id INT)' );
		$driver->query( 'CREATE TABLE has_rowid (rowid INT, id INT)' );
		$driver->query( 'INSERT INTO t1 VALUES (1), (2)' );
		$driver->query( 'INSERT INTO t2 VALUES (1), (3)' );
		$driver->query( 'INSERT INTO has_rowid VALUES (101, 1), (102, 2)' );

		$expected_t1_rows        = array(
			array( 'id' => 1 ),
			array( 'id' => 2 ),
		);
		$expected_t2_rows        = array(
			array( 'id' => 1 ),
			array( 'id' => 3 ),
		);
		$expected_has_rowid_rows = array(
			array(
				'rowid' => 101,
				'id'    => 1,
			),
			array(
				'rowid' => 102,
				'id'    => 2,
			),
		);

		foreach (
			array(
				array(
					'sql'     => 'DELETE a, b FROM t1 a LEFT JOIN t2 b ON a.id = b.id',
					'message' => 'Only comma joins, CROSS JOIN, and INNER JOIN ... ON or USING are supported',
				),
				array(
					'sql'     => 'DELETE a, b FROM t1 a RIGHT JOIN t2 b ON a.id = b.id',
					'message' => 'Only comma joins, CROSS JOIN, and INNER JOIN ... ON or USING are supported',
				),
				array(
					'sql'     => 'DELETE a, b FROM t1 a NATURAL JOIN t2 b',
					'message' => 'Only comma joins, CROSS JOIN, and INNER JOIN ... ON or USING are supported',
				),
				array(
					'sql'     => 'DELETE a, b FROM t1 a STRAIGHT_JOIN t2 b ON a.id = b.id',
					'message' => 'Only comma joins, CROSS JOIN, and INNER JOIN ... ON or USING are supported',
				),
				array(
					'sql'     => 'DELETE a, b FROM t1 a JOIN t2 b USING (missing_id)',
					'message' => "Unknown JOIN ... USING column 'missing_id'",
				),
				array(
					'sql'     => 'DELETE t1 FROM t1 a JOIN t2 b ON a.id = b.id',
					'message' => "Unknown DELETE target alias 't1'",
				),
				array(
					'sql'     => 'DELETE a FROM t1 a JOIN t2 a ON a.id = a.id',
					'message' => "Duplicate table alias 'a'",
				),
				array(
					'sql'     => 'DELETE a FROM t1 a JOIN (SELECT id FROM t2) a ON a.id = a.id',
					'message' => "Duplicate table alias 'a'",
				),
				array(
					'sql'     => 'DELETE b FROM t1 a JOIN (SELECT id FROM t2) b ON a.id = b.id',
					'message' => "Derived table alias 'b' cannot be targeted",
				),
				array(
					'sql'     => 'DELETE t FROM information_schema.tables t',
					'message' => "Access denied for user 'duckdb'@'%' to database 'information_schema'",
				),
				array(
					'sql'     => 'DELETE r, b FROM has_rowid r JOIN t2 b ON r.id = b.id WHERE r.id = 1',
					'message' => 'ORDER BY/LIMIT rewrites require a table without a user-defined rowid column.',
				),
				array(
					'sql'     => 'DELETE a, m FROM t1 a JOIN __wp_duckdb_column_metadata m ON a.id = m.id',
					'message' => 'Internal DuckDB metadata tables cannot be modified',
				),
				array(
					'sql'     => 'DELETE a FROM t1 a JOIN t2 b ON a.id = b.id ORDER BY a.id LIMIT 1',
					'message' => 'DuckDB driver could not parse MySQL statement',
				),
			) as $rejection
		) {
			try {
				$driver->query( $rejection['sql'] );
				$this->fail( 'Expected multi-table DELETE rejection for SQL: ' . $rejection['sql'] );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $rejection['message'], $e->getMessage() );
			}

			$this->assertSame(
				$expected_t1_rows,
				$driver->query( 'SELECT id FROM t1 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'Rejected multi-table DELETE mutated t1 for SQL: ' . $rejection['sql']
			);
			$this->assertSame(
				$expected_t2_rows,
				$driver->query( 'SELECT id FROM t2 ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'Rejected multi-table DELETE mutated t2 for SQL: ' . $rejection['sql']
			);
			$this->assertSame(
				$expected_has_rowid_rows,
				$driver->query( 'SELECT rowid, id FROM has_rowid ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'Rejected multi-table DELETE mutated has_rowid for SQL: ' . $rejection['sql']
			);
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

	public function test_simple_select_result_metadata_uses_recorded_table_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_test',
			)
		);
		$driver->query(
			"CREATE TABLE `wp_posts` (
				`ID` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_title` VARCHAR(191) NOT NULL DEFAULT '',
				`post_content` LONGTEXT,
				PRIMARY KEY (`ID`)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$result = $driver->query( 'SELECT p.ID AS post_id, p.post_title FROM wp_posts AS p WHERE p.ID = 0' );

		$this->assertSame( 2, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'post_id', $id_meta['name'] );
		$this->assertSame( 'p', $id_meta['table'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_posts', $id_meta['mysqli:orgtable'] );
		$this->assertSame( 'wordpress_test', $id_meta['mysqli:db'] );
		$this->assertSame( 20, $id_meta['len'] );
		$this->assertSame( 63, $id_meta['mysqli:charsetnr'] );
		$this->assertSame( 8, $id_meta['mysqli:type'] );

		$title_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'post_title', $title_meta['name'] );
		$this->assertSame( 'p', $title_meta['table'] );
		$this->assertSame( 'post_title', $title_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_posts', $title_meta['mysqli:orgtable'] );
		$this->assertSame( 764, $title_meta['len'] );
		$this->assertSame( 255, $title_meta['mysqli:charsetnr'] );
		$this->assertSame( 253, $title_meta['mysqli:type'] );

		$expression = $driver->query( 'SELECT COUNT(*) AS post_count FROM wp_posts' );
		$this->assertSame( array( 'name' => 'post_count' ), $expression->getColumnMeta( 0 ) );

		$update = $driver->query( "UPDATE wp_posts SET post_title = 'draft' WHERE ID = 0" );
		$this->assertSame( 0, $update->columnCount() );
		$this->assertFalse( $update->getColumnMeta( 0 ) );
	}

	public function test_write_and_control_statements_expose_empty_result_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		foreach (
			array(
				'CREATE TABLE empty_meta (id INT, note VARCHAR(20))',
				'CREATE INDEX empty_meta_note ON empty_meta (note)',
				'ALTER TABLE empty_meta ADD COLUMN extra INT',
				'TRUNCATE TABLE empty_meta',
				'DROP INDEX empty_meta_note ON empty_meta',
				'SET autocommit=1',
				'START TRANSACTION',
				'COMMIT',
				'LOCK TABLES empty_meta READ',
				'UNLOCK TABLES',
				'DROP TABLE empty_meta',
			) as $sql
		) {
			$this->assert_empty_statement_result( $driver->query( $sql ), $sql );
		}
	}

	public function test_direct_column_metadata_type_matrix_for_supported_mysql_types(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$this->createMetadataTypeMatrixTable( $driver );

		$result = $driver->query( 'SELECT * FROM metadata_type_matrix WHERE id = 0' );

		$this->assertSame( 13, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );

		foreach ( $this->metadataTypeMatrixResultMetadata() as $index => $expected ) {
			$metadata = $result->getColumnMeta( $index );
			foreach ( $expected as $key => $value ) {
				$this->assertArrayHasKey( $key, $metadata, $expected['name'] . ' metadata key ' . $key );
				$this->assertSame( $value, $metadata[ $key ], $expected['name'] . ' metadata key ' . $key );
			}
		}

		$this->assertFalse( $result->getColumnMeta( 13 ) );
	}

	public function test_json_column_storage_and_direct_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			"CREATE TABLE json_type_metadata (
				id INT PRIMARY KEY,
				data JSON,
				required JSON NOT NULL DEFAULT 'null'
			)"
		);
		$driver->query( "INSERT INTO json_type_metadata (id, data, required) VALUES (1, '{\"a\":1}', '{\"required\":true}')" );
		$driver->query( 'INSERT INTO json_type_metadata (id, data) VALUES (2, TRUE)' );
		$driver->query( 'INSERT INTO json_type_metadata (id, data) VALUES (3, 0x62)' );
		$driver->query( "INSERT INTO json_type_metadata (id, data) VALUES (4, x'63')" );

		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'data'     => '{"a":1}',
					'required' => '{"required":true}',
				),
				array(
					'id'       => 2,
					'data'     => '1',
					'required' => 'null',
				),
				array(
					'id'       => 3,
					'data'     => 'b',
					'required' => 'null',
				),
				array(
					'id'       => 4,
					'data'     => 'c',
					'required' => 'null',
				),
			),
			$driver->query( 'SELECT id, data, required FROM json_type_metadata ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$result   = $driver->query( 'SELECT data FROM json_type_metadata WHERE id = 1' );
		$metadata = $result->getColumnMeta( 0 );
		$this->assertSame( array( array( 'data' => '{"a":1}' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );

		$expected = array(
			'native_type'      => 'BLOB',
			'table'            => 'json_type_metadata',
			'name'             => 'data',
			'len'              => 4294967295,
			'precision'        => 0,
			'duckdb:decl_type' => 'json',
			'mysqli:orgname'   => 'data',
			'mysqli:orgtable'  => 'json_type_metadata',
			'mysqli:db'        => 'wp',
			'mysqli:charsetnr' => 255,
			'mysqli:type'      => 245,
		);

		foreach ( $expected as $key => $value ) {
			$this->assertArrayHasKey( $key, $metadata );
			$this->assertSame( $value, $metadata[ $key ], 'JSON metadata key ' . $key );
		}

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'              => 'data',
					'DATA_TYPE'                => 'json',
					'CHARACTER_MAXIMUM_LENGTH' => null,
					'CHARACTER_OCTET_LENGTH'   => null,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'json',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
					CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME,
					COLUMN_TYPE
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'json_type_metadata'
					AND column_name = 'data'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_enum_set_storage_and_direct_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE enum_set_type_metadata (
				id INT PRIMARY KEY,
				status ENUM('red', 'green', 'blue') NOT NULL DEFAULT 'green',
				flags SET('read', 'write', 'exec') DEFAULT 'read,exec',
				quoted ENUM('plain', 'b''b', 'longer') DEFAULT 'b''b'
			)"
		);

		$driver->query( 'INSERT INTO enum_set_type_metadata (id) VALUES (1)' );
		$driver->query( "INSERT INTO enum_set_type_metadata (id, status, flags, quoted) VALUES (2, 'purple', 'read,bogus', 'plain')" );
		$driver->query( "INSERT INTO enum_set_type_metadata (id, status, flags, quoted) VALUES (3, '', '', 'longer')" );
		$driver->query( "UPDATE enum_set_type_metadata SET status = 'cerulean', flags = 'anything' WHERE id = 3" );

		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'status' => 'green',
					'flags'  => 'read,exec',
					'quoted' => "b'b",
				),
				array(
					'id'     => 2,
					'status' => 'purple',
					'flags'  => 'read,bogus',
					'quoted' => 'plain',
				),
				array(
					'id'     => 3,
					'status' => 'cerulean',
					'flags'  => 'anything',
					'quoted' => 'longer',
				),
			),
			$driver->query( 'SELECT id, status, flags, quoted FROM enum_set_type_metadata ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Field'   => 'status',
					'Type'    => "enum('red','green','blue')",
					'Null'    => 'NO',
					'Key'     => '',
					'Default' => 'green',
					'Extra'   => '',
				),
				array(
					'Field'   => 'flags',
					'Type'    => "set('read','write','exec')",
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'read,exec',
					'Extra'   => '',
				),
				array(
					'Field'   => 'quoted',
					'Type'    => "enum('plain','b''b','longer')",
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => "b'b",
					'Extra'   => '',
				),
			),
			array_slice( $driver->query( 'SHOW COLUMNS FROM enum_set_type_metadata' )->fetchAll( PDO::FETCH_ASSOC ), 1 )
		);

		$this->assertSame(
			array(
				array(
					'Field'      => 'status',
					'Type'       => "enum('red','green','blue')",
					'Collation'  => 'utf8mb4_0900_ai_ci',
					'Null'       => 'NO',
					'Key'        => '',
					'Default'    => 'green',
					'Extra'      => '',
					'Privileges' => 'select,insert,update,references',
					'Comment'    => '',
				),
				array(
					'Field'      => 'flags',
					'Type'       => "set('read','write','exec')",
					'Collation'  => 'utf8mb4_0900_ai_ci',
					'Null'       => 'YES',
					'Key'        => '',
					'Default'    => 'read,exec',
					'Extra'      => '',
					'Privileges' => 'select,insert,update,references',
					'Comment'    => '',
				),
				array(
					'Field'      => 'quoted',
					'Type'       => "enum('plain','b''b','longer')",
					'Collation'  => 'utf8mb4_0900_ai_ci',
					'Null'       => 'YES',
					'Key'        => '',
					'Default'    => "b'b",
					'Extra'      => '',
					'Privileges' => 'select,insert,update,references',
					'Comment'    => '',
				),
			),
			array_slice( $driver->query( 'SHOW FULL COLUMNS FROM enum_set_type_metadata' )->fetchAll( PDO::FETCH_ASSOC ), 1 )
		);

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'              => 'status',
					'COLUMN_DEFAULT'           => 'green',
					'IS_NULLABLE'              => 'NO',
					'DATA_TYPE'                => 'enum',
					'CHARACTER_MAXIMUM_LENGTH' => 5,
					'CHARACTER_OCTET_LENGTH'   => 20,
					'CHARACTER_SET_NAME'       => 'utf8mb4',
					'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
					'COLUMN_TYPE'              => "enum('red','green','blue')",
				),
				array(
					'COLUMN_NAME'              => 'flags',
					'COLUMN_DEFAULT'           => 'read,exec',
					'IS_NULLABLE'              => 'YES',
					'DATA_TYPE'                => 'set',
					'CHARACTER_MAXIMUM_LENGTH' => 15,
					'CHARACTER_OCTET_LENGTH'   => 60,
					'CHARACTER_SET_NAME'       => 'utf8mb4',
					'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
					'COLUMN_TYPE'              => "set('read','write','exec')",
				),
				array(
					'COLUMN_NAME'              => 'quoted',
					'COLUMN_DEFAULT'           => "b'b",
					'IS_NULLABLE'              => 'YES',
					'DATA_TYPE'                => 'enum',
					'CHARACTER_MAXIMUM_LENGTH' => 6,
					'CHARACTER_OCTET_LENGTH'   => 24,
					'CHARACTER_SET_NAME'       => 'utf8mb4',
					'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
					'COLUMN_TYPE'              => "enum('plain','b''b','longer')",
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
					CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH,
					CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'enum_set_type_metadata'
					AND column_name IN ('status', 'flags', 'quoted')
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE enum_set_type_metadata' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringContainsString( "`status` enum('red','green','blue') NOT NULL DEFAULT 'green'", $create_sql );
		$this->assertStringContainsString( "`flags` set('read','write','exec') DEFAULT 'read,exec'", $create_sql );
		$this->assertStringContainsString( "`quoted` enum('plain','b''b','longer') DEFAULT 'b''b'", $create_sql );
		$this->assertStringNotContainsString( 'VARCHAR', $create_sql );

		$result = $driver->query( 'SELECT status, flags, quoted FROM enum_set_type_metadata WHERE 0 = 1' );
		$this->assertSame( 3, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );

		$expected_metadata = array(
			array(
				'native_type'      => 'STRING',
				'table'            => 'enum_set_type_metadata',
				'name'             => 'status',
				'len'              => 20,
				'precision'        => 0,
				'duckdb:decl_type' => "enum('red','green','blue')",
				'mysqli:orgname'   => 'status',
				'mysqli:orgtable'  => 'enum_set_type_metadata',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
			array(
				'native_type'      => 'STRING',
				'table'            => 'enum_set_type_metadata',
				'name'             => 'flags',
				'len'              => 60,
				'precision'        => 0,
				'duckdb:decl_type' => "set('read','write','exec')",
				'mysqli:orgname'   => 'flags',
				'mysqli:orgtable'  => 'enum_set_type_metadata',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
			array(
				'native_type'      => 'STRING',
				'table'            => 'enum_set_type_metadata',
				'name'             => 'quoted',
				'len'              => 24,
				'precision'        => 0,
				'duckdb:decl_type' => "enum('plain','b''b','longer')",
				'mysqli:orgname'   => 'quoted',
				'mysqli:orgtable'  => 'enum_set_type_metadata',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
		);

		foreach ( $expected_metadata as $index => $expected ) {
			$metadata = $result->getColumnMeta( $index );
			foreach ( $expected as $key => $value ) {
				$this->assertArrayHasKey( $key, $metadata, $expected['name'] . ' metadata key ' . $key );
				$this->assertSame( $value, $metadata[ $key ], $expected['name'] . ' metadata key ' . $key );
			}
		}
	}

	public function test_type_default_charset_metadata_rows_for_supported_mysql_types(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$this->createMetadataTypeMatrixTable( $driver );

		$show_columns = $this->metadataTypeMatrixShowColumnRows();
		$this->assertSame(
			$show_columns,
			$driver->query( 'SHOW COLUMNS FROM metadata_type_matrix' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			$show_columns,
			$driver->query( 'DESCRIBE metadata_type_matrix' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			$show_columns,
			$driver->query( 'DESC metadata_type_matrix' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$show_full_columns = $this->metadataTypeMatrixShowFullColumnRows();
		$this->assertSame(
			$show_full_columns,
			$driver->query( 'SHOW FULL COLUMNS FROM metadata_type_matrix' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			$show_full_columns,
			$driver->query( 'SHOW FULL FIELDS FROM metadata_type_matrix' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			$this->metadataTypeMatrixInformationSchemaRows(),
			$driver->query(
				"SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
					CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH,
					NUMERIC_PRECISION, NUMERIC_SCALE, DATETIME_PRECISION,
					CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE, COLUMN_KEY,
					EXTRA, COLUMN_COMMENT
				FROM information_schema.columns
				WHERE table_schema = 'wp' AND table_name = 'metadata_type_matrix'
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alias_storage_type_family_metadata_and_writes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alias_storage_types (
				id INT PRIMARY KEY,
				real_col REAL,
				dec_col DEC,
				dec_ps_col DEC(10,2),
				fixed_col FIXED,
				binary_col BINARY,
				binary_len_col BINARY(8),
				varbinary_col VARBINARY(16)
			)'
		);
		$driver->query(
			"INSERT INTO alias_storage_types
				(id, real_col, dec_col, dec_ps_col, fixed_col, binary_col, binary_len_col, varbinary_col)
			VALUES
				(1, '3.5', '4', 5.25, '6', B'01000001', 0x6263, x'646566')"
		);

		$this->assertSame(
			array(
				array(
					'id'         => 1,
					'real_col'   => 3.5,
					'dec_col'    => 4.0,
					'dec_ps_col' => 5.25,
					'fixed_col'  => 6.0,
				),
			),
			$driver->query(
				'SELECT id, real_col, dec_col, dec_ps_col, fixed_col
				FROM alias_storage_types'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'binary_hex'     => '41',
					'binary_len_hex' => '6263',
					'varbinary_hex'  => '646566',
				),
			),
			$driver->query(
				'SELECT LOWER(HEX(binary_col)) AS binary_hex,
					LOWER(HEX(binary_len_col)) AS binary_len_hex,
					LOWER(HEX(varbinary_col)) AS varbinary_hex
				FROM alias_storage_types'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$show_columns = array_slice( $driver->query( 'SHOW COLUMNS FROM alias_storage_types' )->fetchAll( PDO::FETCH_ASSOC ), 1 );
		$this->assertSame(
			array(
				array(
					'Field'   => 'real_col',
					'Type'    => 'double',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'dec_col',
					'Type'    => 'decimal(10,0)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'dec_ps_col',
					'Type'    => 'decimal(10,2)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'fixed_col',
					'Type'    => 'decimal(10,0)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'binary_col',
					'Type'    => 'binary(1)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'binary_len_col',
					'Type'    => 'binary(8)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'varbinary_col',
					'Type'    => 'varbinary(16)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
			),
			$show_columns
		);
		$this->assertSame(
			$show_columns,
			array_slice( $driver->query( 'DESCRIBE alias_storage_types' )->fetchAll( PDO::FETCH_ASSOC ), 1 )
		);

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'              => 'real_col',
					'DATA_TYPE'                => 'double',
					'CHARACTER_MAXIMUM_LENGTH' => null,
					'CHARACTER_OCTET_LENGTH'   => null,
					'NUMERIC_PRECISION'        => 22,
					'NUMERIC_SCALE'            => null,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'double',
				),
				array(
					'COLUMN_NAME'              => 'dec_col',
					'DATA_TYPE'                => 'decimal',
					'CHARACTER_MAXIMUM_LENGTH' => null,
					'CHARACTER_OCTET_LENGTH'   => null,
					'NUMERIC_PRECISION'        => 10,
					'NUMERIC_SCALE'            => 0,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'decimal(10,0)',
				),
				array(
					'COLUMN_NAME'              => 'dec_ps_col',
					'DATA_TYPE'                => 'decimal',
					'CHARACTER_MAXIMUM_LENGTH' => null,
					'CHARACTER_OCTET_LENGTH'   => null,
					'NUMERIC_PRECISION'        => 10,
					'NUMERIC_SCALE'            => 2,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'decimal(10,2)',
				),
				array(
					'COLUMN_NAME'              => 'fixed_col',
					'DATA_TYPE'                => 'decimal',
					'CHARACTER_MAXIMUM_LENGTH' => null,
					'CHARACTER_OCTET_LENGTH'   => null,
					'NUMERIC_PRECISION'        => 10,
					'NUMERIC_SCALE'            => 0,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'decimal(10,0)',
				),
				array(
					'COLUMN_NAME'              => 'binary_col',
					'DATA_TYPE'                => 'binary',
					'CHARACTER_MAXIMUM_LENGTH' => 1,
					'CHARACTER_OCTET_LENGTH'   => 1,
					'NUMERIC_PRECISION'        => null,
					'NUMERIC_SCALE'            => null,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'binary(1)',
				),
				array(
					'COLUMN_NAME'              => 'binary_len_col',
					'DATA_TYPE'                => 'binary',
					'CHARACTER_MAXIMUM_LENGTH' => 8,
					'CHARACTER_OCTET_LENGTH'   => 8,
					'NUMERIC_PRECISION'        => null,
					'NUMERIC_SCALE'            => null,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'binary(8)',
				),
				array(
					'COLUMN_NAME'              => 'varbinary_col',
					'DATA_TYPE'                => 'varbinary',
					'CHARACTER_MAXIMUM_LENGTH' => 16,
					'CHARACTER_OCTET_LENGTH'   => 16,
					'NUMERIC_PRECISION'        => null,
					'NUMERIC_SCALE'            => null,
					'CHARACTER_SET_NAME'       => null,
					'COLLATION_NAME'           => null,
					'COLUMN_TYPE'              => 'varbinary(16)',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
					CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
					CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'alias_storage_types'
					AND column_name <> 'id'
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$result            = $driver->query(
			'SELECT real_col, dec_col, dec_ps_col, fixed_col, binary_col, binary_len_col, varbinary_col
			FROM alias_storage_types
			WHERE 0 = 1'
		);
		$expected_metadata = array(
			array(
				'name'             => 'real_col',
				'native_type'      => 'DOUBLE',
				'len'              => 22,
				'precision'        => 31,
				'duckdb:decl_type' => 'double',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 5,
			),
			array(
				'name'             => 'dec_col',
				'native_type'      => 'NEWDECIMAL',
				'len'              => 10,
				'precision'        => 0,
				'duckdb:decl_type' => 'decimal(10,0)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 246,
			),
			array(
				'name'             => 'dec_ps_col',
				'native_type'      => 'NEWDECIMAL',
				'len'              => 12,
				'precision'        => 2,
				'duckdb:decl_type' => 'decimal(10,2)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 246,
			),
			array(
				'name'             => 'fixed_col',
				'native_type'      => 'NEWDECIMAL',
				'len'              => 10,
				'precision'        => 0,
				'duckdb:decl_type' => 'decimal(10,0)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 246,
			),
			array(
				'name'             => 'binary_col',
				'native_type'      => 'BLOB',
				'len'              => 1,
				'precision'        => 0,
				'duckdb:decl_type' => 'binary(1)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 254,
			),
			array(
				'name'             => 'binary_len_col',
				'native_type'      => 'BLOB',
				'len'              => 8,
				'precision'        => 0,
				'duckdb:decl_type' => 'binary(8)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 254,
			),
			array(
				'name'             => 'varbinary_col',
				'native_type'      => 'BLOB',
				'len'              => 16,
				'precision'        => 0,
				'duckdb:decl_type' => 'varbinary(16)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 253,
			),
		);
		foreach ( $expected_metadata as $index => $expected ) {
			$metadata = $result->getColumnMeta( $index );
			foreach ( $expected as $key => $value ) {
				$this->assertSame( $value, $metadata[ $key ], $expected['name'] . ' metadata key ' . $key );
			}
		}

		$create_sql = $driver->query( 'SHOW CREATE TABLE alias_storage_types' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		foreach (
			array(
				'`real_col` double',
				'`dec_col` decimal(10,0)',
				'`dec_ps_col` decimal(10,2)',
				'`fixed_col` decimal(10,0)',
				'`binary_col` binary(1)',
				'`binary_len_col` binary(8)',
				'`varbinary_col` varbinary(16)',
			) as $expected_fragment
		) {
			$this->assertStringContainsString( $expected_fragment, $create_sql );
		}
	}

	public function test_national_character_type_family_metadata_defaults_and_writes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE national_character_types (
				id INT PRIMARY KEY,
				plain_nchar NCHAR DEFAULT 'a',
				nchar_len NCHAR(10) DEFAULT 'bee',
				national_plain NATIONAL CHAR DEFAULT 'c',
				national_len NATIONAL CHAR (10) DEFAULT 'dee',
				nchar_varchar NCHAR VARCHAR(255) DEFAULT 'echo',
				nchar_varying NCHAR VARYING(32) DEFAULT 'foxtrot',
				nvarchar_col NVARCHAR(20) DEFAULT 'golf',
				national_varchar NATIONAL VARCHAR(30) DEFAULT 'hotel',
				national_char_varying NATIONAL CHAR VARYING(40) DEFAULT 'india',
				national_character_varying NATIONAL CHARACTER VARYING(50) DEFAULT 'juliet'
			)"
		);
		$driver->query( 'INSERT INTO national_character_types (id) VALUES (1)' );
		$driver->query(
			"INSERT INTO national_character_types
				(id, plain_nchar, nchar_len, national_plain, national_len,
					nchar_varchar, nchar_varying, nvarchar_col, national_varchar,
					national_char_varying, national_character_varying)
			VALUES
				(2, 'aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh', 'ii', 'jj')"
		);

		$this->assertSame(
			array(
				array(
					'id'                         => 1,
					'plain_nchar'                => 'a',
					'nchar_len'                  => 'bee',
					'national_plain'             => 'c',
					'national_len'               => 'dee',
					'nchar_varchar'              => 'echo',
					'nchar_varying'              => 'foxtrot',
					'nvarchar_col'               => 'golf',
					'national_varchar'           => 'hotel',
					'national_char_varying'      => 'india',
					'national_character_varying' => 'juliet',
				),
				array(
					'id'                         => 2,
					'plain_nchar'                => 'aa',
					'nchar_len'                  => 'bb',
					'national_plain'             => 'cc',
					'national_len'               => 'dd',
					'nchar_varchar'              => 'ee',
					'nchar_varying'              => 'ff',
					'nvarchar_col'               => 'gg',
					'national_varchar'           => 'hh',
					'national_char_varying'      => 'ii',
					'national_character_varying' => 'jj',
				),
			),
			$driver->query(
				'SELECT id, plain_nchar, nchar_len, national_plain, national_len,
					nchar_varchar, nchar_varying, nvarchar_col, national_varchar,
					national_char_varying, national_character_varying
				FROM national_character_types
				ORDER BY id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$show_columns = array_slice( $driver->query( 'SHOW COLUMNS FROM national_character_types' )->fetchAll( PDO::FETCH_ASSOC ), 1 );
		$this->assertSame(
			array(
				array(
					'Field'   => 'plain_nchar',
					'Type'    => 'char(1)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'a',
					'Extra'   => '',
				),
				array(
					'Field'   => 'nchar_len',
					'Type'    => 'char(10)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'bee',
					'Extra'   => '',
				),
				array(
					'Field'   => 'national_plain',
					'Type'    => 'char(1)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'c',
					'Extra'   => '',
				),
				array(
					'Field'   => 'national_len',
					'Type'    => 'char(10)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'dee',
					'Extra'   => '',
				),
				array(
					'Field'   => 'nchar_varchar',
					'Type'    => 'varchar(255)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'echo',
					'Extra'   => '',
				),
				array(
					'Field'   => 'nchar_varying',
					'Type'    => 'varchar(32)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'foxtrot',
					'Extra'   => '',
				),
				array(
					'Field'   => 'nvarchar_col',
					'Type'    => 'varchar(20)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'golf',
					'Extra'   => '',
				),
				array(
					'Field'   => 'national_varchar',
					'Type'    => 'varchar(30)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'hotel',
					'Extra'   => '',
				),
				array(
					'Field'   => 'national_char_varying',
					'Type'    => 'varchar(40)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'india',
					'Extra'   => '',
				),
				array(
					'Field'   => 'national_character_varying',
					'Type'    => 'varchar(50)',
					'Null'    => 'YES',
					'Key'     => '',
					'Default' => 'juliet',
					'Extra'   => '',
				),
			),
			$show_columns
		);
		$this->assertSame(
			$show_columns,
			array_slice( $driver->query( 'DESCRIBE national_character_types' )->fetchAll( PDO::FETCH_ASSOC ), 1 )
		);

		$this->assertSame(
			array_fill( 0, 10, 'utf8_general_ci' ),
			array_column(
				array_slice( $driver->query( 'SHOW FULL COLUMNS FROM national_character_types' )->fetchAll( PDO::FETCH_ASSOC ), 1 ),
				'Collation'
			)
		);

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'              => 'plain_nchar',
					'COLUMN_DEFAULT'           => 'a',
					'DATA_TYPE'                => 'char',
					'CHARACTER_MAXIMUM_LENGTH' => 1,
					'CHARACTER_OCTET_LENGTH'   => 3,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'char(1)',
				),
				array(
					'COLUMN_NAME'              => 'nchar_len',
					'COLUMN_DEFAULT'           => 'bee',
					'DATA_TYPE'                => 'char',
					'CHARACTER_MAXIMUM_LENGTH' => 10,
					'CHARACTER_OCTET_LENGTH'   => 30,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'char(10)',
				),
				array(
					'COLUMN_NAME'              => 'national_plain',
					'COLUMN_DEFAULT'           => 'c',
					'DATA_TYPE'                => 'char',
					'CHARACTER_MAXIMUM_LENGTH' => 1,
					'CHARACTER_OCTET_LENGTH'   => 3,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'char(1)',
				),
				array(
					'COLUMN_NAME'              => 'national_len',
					'COLUMN_DEFAULT'           => 'dee',
					'DATA_TYPE'                => 'char',
					'CHARACTER_MAXIMUM_LENGTH' => 10,
					'CHARACTER_OCTET_LENGTH'   => 30,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'char(10)',
				),
				array(
					'COLUMN_NAME'              => 'nchar_varchar',
					'COLUMN_DEFAULT'           => 'echo',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 255,
					'CHARACTER_OCTET_LENGTH'   => 765,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(255)',
				),
				array(
					'COLUMN_NAME'              => 'nchar_varying',
					'COLUMN_DEFAULT'           => 'foxtrot',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 32,
					'CHARACTER_OCTET_LENGTH'   => 96,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(32)',
				),
				array(
					'COLUMN_NAME'              => 'nvarchar_col',
					'COLUMN_DEFAULT'           => 'golf',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 20,
					'CHARACTER_OCTET_LENGTH'   => 60,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(20)',
				),
				array(
					'COLUMN_NAME'              => 'national_varchar',
					'COLUMN_DEFAULT'           => 'hotel',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 30,
					'CHARACTER_OCTET_LENGTH'   => 90,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(30)',
				),
				array(
					'COLUMN_NAME'              => 'national_char_varying',
					'COLUMN_DEFAULT'           => 'india',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 40,
					'CHARACTER_OCTET_LENGTH'   => 120,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(40)',
				),
				array(
					'COLUMN_NAME'              => 'national_character_varying',
					'COLUMN_DEFAULT'           => 'juliet',
					'DATA_TYPE'                => 'varchar',
					'CHARACTER_MAXIMUM_LENGTH' => 50,
					'CHARACTER_OCTET_LENGTH'   => 150,
					'CHARACTER_SET_NAME'       => 'utf8',
					'COLLATION_NAME'           => 'utf8_general_ci',
					'COLUMN_TYPE'              => 'varchar(50)',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
					CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'national_character_types'
					AND column_name <> 'id'
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$result            = $driver->query(
			'SELECT plain_nchar, nchar_len, nchar_varchar, nvarchar_col
			FROM national_character_types
			WHERE 0 = 1'
		);
		$expected_metadata = array(
			array(
				'name'             => 'plain_nchar',
				'native_type'      => 'STRING',
				'len'              => 4,
				'precision'        => 0,
				'duckdb:decl_type' => 'char(1)',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
			array(
				'name'             => 'nchar_len',
				'native_type'      => 'STRING',
				'len'              => 40,
				'precision'        => 0,
				'duckdb:decl_type' => 'char(10)',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
			array(
				'name'             => 'nchar_varchar',
				'native_type'      => 'VAR_STRING',
				'len'              => 1020,
				'precision'        => 0,
				'duckdb:decl_type' => 'varchar(255)',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 253,
			),
			array(
				'name'             => 'nvarchar_col',
				'native_type'      => 'VAR_STRING',
				'len'              => 80,
				'precision'        => 0,
				'duckdb:decl_type' => 'varchar(20)',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 253,
			),
		);
		foreach ( $expected_metadata as $index => $expected ) {
			$metadata = $result->getColumnMeta( $index );
			foreach ( $expected as $key => $value ) {
				$this->assertSame( $value, $metadata[ $key ], $expected['name'] . ' metadata key ' . $key );
			}
		}

		$create_sql = $driver->query( 'SHOW CREATE TABLE national_character_types' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		foreach (
			array(
				"`plain_nchar` char(1) DEFAULT 'a'",
				"`nchar_len` char(10) DEFAULT 'bee'",
				"`national_plain` char(1) DEFAULT 'c'",
				"`national_len` char(10) DEFAULT 'dee'",
				"`nchar_varchar` varchar(255) DEFAULT 'echo'",
				"`nchar_varying` varchar(32) DEFAULT 'foxtrot'",
				"`nvarchar_col` varchar(20) DEFAULT 'golf'",
				"`national_varchar` varchar(30) DEFAULT 'hotel'",
				"`national_char_varying` varchar(40) DEFAULT 'india'",
				"`national_character_varying` varchar(50) DEFAULT 'juliet'",
			) as $expected_fragment
		) {
			$this->assertStringContainsString( $expected_fragment, $create_sql );
		}
	}

	public function test_bit_type_family_metadata_defaults_and_writes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE bit_type_family (
				id INT PRIMARY KEY,
				plain BIT,
				flags BIT(4) DEFAULT b'0101',
				from_hex BIT(8) DEFAULT 0x0a,
				quoted_zero BIT(1) DEFAULT '0',
				integer_five BIT(4) DEFAULT 5,
				truthy BIT(1) DEFAULT TRUE,
				falsey BIT(1) DEFAULT FALSE
			)"
		);
		$driver->query( "INSERT INTO bit_type_family (id, plain) VALUES (1, b'0011')" );
		$driver->query(
			"INSERT INTO bit_type_family
				(id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey)
			VALUES
				(2, 0b0100, x'05', 0x06, 1, '7', FALSE, TRUE)"
		);
		$driver->query(
			"INSERT INTO bit_type_family
				(id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey)
			VALUES
				(3, '7', 8, b'00001001', 0, 10, TRUE, FALSE)"
		);
		$driver->query(
			"UPDATE bit_type_family
			SET plain = b'1010',
				flags = 0x0b,
				from_hex = 12,
				truthy = TRUE,
				falsey = FALSE
			WHERE id = 3"
		);

		$this->assertSame(
			array(
				array(
					'id'           => 1,
					'plain'        => 3,
					'flags'        => 5,
					'from_hex'     => 10,
					'quoted_zero'  => 0,
					'integer_five' => 5,
					'truthy'       => 1,
					'falsey'       => 0,
				),
				array(
					'id'           => 2,
					'plain'        => 4,
					'flags'        => 5,
					'from_hex'     => 6,
					'quoted_zero'  => 1,
					'integer_five' => 7,
					'truthy'       => 0,
					'falsey'       => 1,
				),
				array(
					'id'           => 3,
					'plain'        => 10,
					'flags'        => 11,
					'from_hex'     => 12,
					'quoted_zero'  => 0,
					'integer_five' => 10,
					'truthy'       => 1,
					'falsey'       => 0,
				),
			),
			$driver->query(
				'SELECT id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey
				FROM bit_type_family
				ORDER BY id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$show_columns = array_column(
			$driver->query( 'SHOW COLUMNS FROM bit_type_family' )->fetchAll( PDO::FETCH_ASSOC ),
			null,
			'Field'
		);
		$this->assertSame( 'bit(1)', $show_columns['plain']['Type'] );
		$this->assertSame( 'bit(4)', $show_columns['flags']['Type'] );
		$this->assertSame( 'bit(8)', $show_columns['from_hex']['Type'] );
		$this->assertNull( $show_columns['plain']['Default'] );
		$this->assertSame( "b'101'", $show_columns['flags']['Default'] );
		$this->assertSame( "b'1010'", $show_columns['from_hex']['Default'] );
		$this->assertSame( "b'0'", $show_columns['quoted_zero']['Default'] );
		$this->assertSame( "b'101'", $show_columns['integer_five']['Default'] );
		$this->assertSame( "b'1'", $show_columns['truthy']['Default'] );
		$this->assertSame( "b'0'", $show_columns['falsey']['Default'] );
		$this->assertSame(
			$driver->query( 'SHOW COLUMNS FROM bit_type_family' )->fetchAll( PDO::FETCH_ASSOC ),
			$driver->query( 'DESCRIBE bit_type_family' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'        => 'plain',
					'COLUMN_DEFAULT'     => null,
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 1,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(1)',
				),
				array(
					'COLUMN_NAME'        => 'flags',
					'COLUMN_DEFAULT'     => "b'101'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 4,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(4)',
				),
				array(
					'COLUMN_NAME'        => 'from_hex',
					'COLUMN_DEFAULT'     => "b'1010'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 8,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(8)',
				),
				array(
					'COLUMN_NAME'        => 'quoted_zero',
					'COLUMN_DEFAULT'     => "b'0'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 1,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(1)',
				),
				array(
					'COLUMN_NAME'        => 'integer_five',
					'COLUMN_DEFAULT'     => "b'101'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 4,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(4)',
				),
				array(
					'COLUMN_NAME'        => 'truthy',
					'COLUMN_DEFAULT'     => "b'1'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 1,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(1)',
				),
				array(
					'COLUMN_NAME'        => 'falsey',
					'COLUMN_DEFAULT'     => "b'0'",
					'DATA_TYPE'          => 'bit',
					'NUMERIC_PRECISION'  => 1,
					'NUMERIC_SCALE'      => null,
					'CHARACTER_SET_NAME' => null,
					'COLLATION_NAME'     => null,
					'COLUMN_TYPE'        => 'bit(1)',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, NUMERIC_PRECISION,
					NUMERIC_SCALE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'bit_type_family'
					AND column_name <> 'id'
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$result            = $driver->query( 'SELECT plain, flags FROM bit_type_family WHERE 0 = 1' );
		$expected_metadata = array(
			array(
				'name'             => 'plain',
				'native_type'      => 'BIT',
				'len'              => 1,
				'precision'        => 0,
				'duckdb:decl_type' => 'bit(1)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 16,
			),
			array(
				'name'             => 'flags',
				'native_type'      => 'BIT',
				'len'              => 1,
				'precision'        => 0,
				'duckdb:decl_type' => 'bit(4)',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 16,
			),
		);
		foreach ( $expected_metadata as $index => $expected ) {
			$metadata = $result->getColumnMeta( $index );
			foreach ( $expected as $key => $value ) {
				$this->assertSame( $value, $metadata[ $key ], $expected['name'] . ' metadata key ' . $key );
			}
		}

		$create_sql = $driver->query( 'SHOW CREATE TABLE bit_type_family' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		foreach (
			array(
				'`plain` bit(1) DEFAULT NULL',
				"`flags` bit(4) DEFAULT b'101'",
				"`from_hex` bit(8) DEFAULT b'1010'",
				"`quoted_zero` bit(1) DEFAULT b'0'",
				"`integer_five` bit(4) DEFAULT b'101'",
				"`truthy` bit(1) DEFAULT b'1'",
				"`falsey` bit(1) DEFAULT b'0'",
			) as $expected_fragment
		) {
			$this->assertStringContainsString( $expected_fragment, $create_sql );
		}
	}

	public function test_bit_type_family_rejects_out_of_range_literals_without_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$max_bits       = str_repeat( '1', 63 );
		$too_large_bits = str_repeat( '1', 64 );
		$driver->query( "CREATE TABLE bit_boundary (id INT PRIMARY KEY, value BIT(63) DEFAULT b'{$max_bits}')" );
		$driver->query( 'INSERT INTO bit_boundary (id, value) VALUES (1, 0x7fffffffffffffff)' );
		$row = $driver->query( 'SELECT value FROM bit_boundary' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( '9223372036854775807', (string) $row['value'] );

		try {
			$driver->query( "CREATE TABLE bit_default_overflow (value BIT(64) DEFAULT b'{$too_large_bits}')" );
			$this->fail( 'Expected oversized BIT default to be rejected.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'BIT literal exceeds signed BIGINT range', $e->getMessage() );
		}
		$this->assertSame(
			array(),
			$driver->query( "SHOW TABLES LIKE 'bit_default_overflow'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'CREATE TABLE bit_write_overflow (id INT PRIMARY KEY, value BIT)' );
		$driver->query( "INSERT INTO bit_write_overflow (id, value) VALUES (1, b'101')" );

		foreach (
			array(
				"INSERT INTO bit_write_overflow (id, value) VALUES (2, 1), (3, b'{$too_large_bits}')",
				'INSERT INTO bit_write_overflow (id, value) VALUES (2, 9223372036854775808)',
				'INSERT INTO bit_write_overflow (id, value) VALUES (2, +9223372036854775808)',
				"INSERT INTO bit_write_overflow (id, value) VALUES (2, '9223372036854775808')",
				'UPDATE bit_write_overflow SET value = 0x8000000000000000 WHERE id = 1',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected oversized BIT write to be rejected for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'BIT literal exceeds signed BIGINT range', $e->getMessage() );
			}

			$this->assertSame(
				array(
					array(
						'id'    => 1,
						'value' => 5,
					),
				),
				$driver->query( 'SELECT id, value FROM bit_write_overflow ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
			);
		}
	}

	public function test_expression_and_admin_result_metadata_provider_documents_name_only_contract(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE metadata_provider_items (
				id INT PRIMARY KEY,
				name VARCHAR(20)
			)'
		);
		$driver->query( 'CREATE INDEX metadata_provider_items_name ON metadata_provider_items (name)' );
		$driver->query( "INSERT INTO metadata_provider_items (id, name) VALUES (1, 'alpha')" );
		$driver->query( 'SELECT id FROM metadata_provider_items' );

		$cases = array(
			array(
				'sql'     => 'SELECT COUNT(*) AS post_count FROM metadata_provider_items',
				'columns' => array( 'post_count' ),
			),
			array(
				'sql'     => 'SELECT FOUND_ROWS() AS found_rows',
				'columns' => array( 'found_rows' ),
			),
			array(
				'sql'     => 'SELECT DATABASE() AS db_name',
				'columns' => array( 'db_name' ),
			),
			array(
				'sql'     => 'SELECT CAST(42 AS SIGNED) AS signed_value',
				'columns' => array( 'signed_value' ),
			),
			array(
				'sql'     => "SELECT CONCAT('a', 'b') AS concat_value",
				'columns' => array( 'concat_value' ),
			),
			array(
				'sql'     => "SHOW COLLATION LIKE 'utf8_bin'",
				'columns' => array( 'Collation', 'Charset', 'Id', 'Default', 'Compiled', 'Sortlen', 'Pad_attribute' ),
			),
			array(
				'sql'     => 'SHOW DATABASES',
				'columns' => array( 'Database' ),
			),
			array(
				'sql'     => 'SHOW GRANTS',
				'columns' => array( 'Grants for root@%' ),
			),
			array(
				'sql'     => 'SHOW VARIABLES',
				'columns' => array( 'Variable_name', 'Value' ),
			),
			array(
				'sql'     => 'SHOW TABLES',
				'columns' => array( 'Tables_in_wp' ),
			),
			array(
				'sql'     => 'SHOW FULL TABLES',
				'columns' => array( 'Tables_in_wp', 'Table_type' ),
			),
			array(
				'sql'     => 'SHOW COLUMNS FROM metadata_provider_items',
				'columns' => array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			),
			array(
				'sql'     => 'SHOW FULL COLUMNS FROM metadata_provider_items',
				'columns' => array( 'Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra', 'Privileges', 'Comment' ),
			),
			array(
				'sql'     => 'SHOW INDEX FROM metadata_provider_items',
				'columns' => array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Cardinality', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment', 'Index_comment', 'Visible', 'Expression' ),
			),
			array(
				'sql'     => 'SHOW CREATE TABLE metadata_provider_items',
				'columns' => array( 'Table', 'Create Table' ),
			),
			array(
				'sql'     => "SHOW TABLE STATUS LIKE 'metadata_provider_items'",
				'columns' => array( 'Name', 'Engine', 'Version', 'Row_format', 'Rows', 'Avg_row_length', 'Data_length', 'Max_data_length', 'Index_length', 'Data_free', 'Auto_increment', 'Create_time', 'Update_time', 'Check_time', 'Collation', 'Checksum', 'Create_options', 'Comment' ),
			),
			array(
				'sql'     => 'DESCRIBE metadata_provider_items',
				'columns' => array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ),
			),
			array(
				'sql'     => 'CHECK TABLE metadata_provider_items',
				'columns' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			),
			array(
				'sql'     => 'ANALYZE TABLE metadata_provider_items',
				'columns' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			),
			array(
				'sql'     => 'OPTIMIZE TABLE metadata_provider_items',
				'columns' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			),
			array(
				'sql'     => 'REPAIR TABLE metadata_provider_items',
				'columns' => array( 'Table', 'Op', 'Msg_type', 'Msg_text' ),
			),
		);

		foreach ( $cases as $case ) {
			$sql            = $case['sql'];
			$expected_names = $case['columns'];
			$result         = $driver->query( $sql );

			$this->assertSame( 0, $result->rowCount(), $sql );
			$this->assertSame( count( $expected_names ), $result->columnCount(), $sql );

			foreach ( $expected_names as $index => $expected_name ) {
				$this->assertSame( array( 'name' => $expected_name ), $result->getColumnMeta( $index ), $sql );
			}

			$this->assertFalse( $result->getColumnMeta( count( $expected_names ) ), $sql );
		}
	}

	public function test_simple_select_wildcard_result_metadata_uses_recorded_table_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_test',
			)
		);
		$driver->query(
			"CREATE TABLE `wp_wildcard_posts` (
				`ID` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_title` VARCHAR(191) NOT NULL DEFAULT '',
				`post_content` LONGTEXT,
				PRIMARY KEY (`ID`)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_wildcard_posts (post_title, post_content) VALUES
			('Hello', 'First post'),
			('World', 'Second post')"
		);

		$result = $driver->query( 'SELECT * FROM wp_wildcard_posts ORDER BY ID LIMIT 1' );

		$this->assertSame( 3, $result->columnCount() );
		$this->assertSame(
			array(
				array(
					'ID'           => 1,
					'post_title'   => 'Hello',
					'post_content' => 'First post',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'ID', $id_meta['name'] );
		$this->assertSame( 'wp_wildcard_posts', $id_meta['table'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_wildcard_posts', $id_meta['mysqli:orgtable'] );
		$this->assertSame( 'wordpress_test', $id_meta['mysqli:db'] );
		$this->assertSame( 20, $id_meta['len'] );
		$this->assertSame( 63, $id_meta['mysqli:charsetnr'] );
		$this->assertSame( 8, $id_meta['mysqli:type'] );

		$title_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'post_title', $title_meta['name'] );
		$this->assertSame( 'wp_wildcard_posts', $title_meta['table'] );
		$this->assertSame( 'post_title', $title_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_wildcard_posts', $title_meta['mysqli:orgtable'] );
		$this->assertSame( 764, $title_meta['len'] );
		$this->assertSame( 255, $title_meta['mysqli:charsetnr'] );
		$this->assertSame( 253, $title_meta['mysqli:type'] );

		$content_meta = $result->getColumnMeta( 2 );
		$this->assertSame( 'post_content', $content_meta['name'] );
		$this->assertSame( 'post_content', $content_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_wildcard_posts', $content_meta['mysqli:orgtable'] );
		$this->assertSame( 4294967295, $content_meta['len'] );
		$this->assertSame( 255, $content_meta['mysqli:charsetnr'] );
		$this->assertSame( 252, $content_meta['mysqli:type'] );
	}

	public function test_simple_select_alias_wildcard_result_metadata_uses_alias_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_test',
			)
		);
		$driver->query(
			"CREATE TABLE wp_alias_wildcard_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_title VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (ID)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "INSERT INTO wp_alias_wildcard_posts (post_title) VALUES ('Hello')" );

		$result = $driver->query( 'SELECT p.* FROM wp_alias_wildcard_posts AS p WHERE p.ID = 1' );

		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'Hello',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'ID', $id_meta['name'] );
		$this->assertSame( 'p', $id_meta['table'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_alias_wildcard_posts', $id_meta['mysqli:orgtable'] );

		$title_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'post_title', $title_meta['name'] );
		$this->assertSame( 'p', $title_meta['table'] );
		$this->assertSame( 'post_title', $title_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_alias_wildcard_posts', $title_meta['mysqli:orgtable'] );
	}

	public function test_simple_select_wildcard_metadata_uses_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_shadow_wildcard_posts (
				persistent_id BIGINT(20) UNSIGNED NOT NULL,
				persistent_label VARCHAR(191) NOT NULL DEFAULT ''
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TEMPORARY TABLE wp_shadow_wildcard_posts (
				temp_id INT NOT NULL,
				temp_label VARCHAR(20) NOT NULL DEFAULT ''
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "INSERT INTO wp_shadow_wildcard_posts (temp_id, temp_label) VALUES (7, 'temporary')" );

		$result = $driver->query( 'SELECT * FROM wp_shadow_wildcard_posts' );

		$this->assertSame(
			array(
				array(
					'temp_id'    => 7,
					'temp_label' => 'temporary',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'temp_id', $id_meta['name'] );
		$this->assertSame( 'temp_id', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_shadow_wildcard_posts', $id_meta['mysqli:orgtable'] );
		$this->assertSame( 11, $id_meta['len'] );
		$this->assertSame( 3, $id_meta['mysqli:type'] );

		$label_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'temp_label', $label_meta['name'] );
		$this->assertSame( 'temp_label', $label_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_shadow_wildcard_posts', $label_meta['mysqli:orgtable'] );
		$this->assertSame( 80, $label_meta['len'] );
		$this->assertSame( 253, $label_meta['mysqli:type'] );
	}

	public function test_sql_calc_found_rows_wildcard_result_metadata_is_preserved(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_found_rows_wildcard_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_title VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (ID)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_found_rows_wildcard_posts (post_title) VALUES
			('Hello'),
			('World')"
		);

		$result = $driver->query( 'SELECT SQL_CALC_FOUND_ROWS * FROM wp_found_rows_wildcard_posts ORDER BY ID LIMIT 1' );

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'ID', $id_meta['name'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_found_rows_wildcard_posts', $id_meta['mysqli:orgtable'] );

		$title_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'post_title', $title_meta['name'] );
		$this->assertSame( 'post_title', $title_meta['mysqli:orgname'] );
		$this->assertSame( 'wp_found_rows_wildcard_posts', $title_meta['mysqli:orgtable'] );

		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'Hello',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_non_simple_wildcard_selects_do_not_infer_origin_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_join_wildcard_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_title VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (ID)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wp_join_wildcard_meta (
				post_id BIGINT(20) UNSIGNED NOT NULL,
				meta_key VARCHAR(191) NOT NULL DEFAULT ''
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "INSERT INTO wp_join_wildcard_posts (post_title) VALUES ('Hello')" );
		$driver->query( "INSERT INTO wp_join_wildcard_meta (post_id, meta_key) VALUES (1, '_edit_lock')" );

		$join = $driver->query(
			'SELECT p.*, m.meta_key FROM wp_join_wildcard_posts AS p JOIN wp_join_wildcard_meta AS m ON p.ID = m.post_id'
		);

		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'Hello',
					'meta_key'   => '_edit_lock',
				),
			),
			$join->fetchAll( PDO::FETCH_ASSOC )
		);

		$join_meta = $join->getColumnMeta( 0 );
		$this->assertSame( 'ID', $join_meta['name'] );
		$this->assertArrayNotHasKey( 'mysqli:orgname', $join_meta );
		$this->assertArrayNotHasKey( 'mysqli:orgtable', $join_meta );

		$expression = $driver->query( 'SELECT *, CONCAT(post_title, post_title) AS doubled FROM wp_join_wildcard_posts' );
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'Hello',
					'doubled'    => 'HelloHello',
				),
			),
			$expression->fetchAll( PDO::FETCH_ASSOC )
		);

		$expression_meta = $expression->getColumnMeta( 0 );
		$this->assertSame( 'ID', $expression_meta['name'] );
		$this->assertArrayNotHasKey( 'mysqli:orgname', $expression_meta );
		$this->assertArrayNotHasKey( 'mysqli:orgtable', $expression_meta );
	}

	public function test_regexp_predicates_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE options (option_name VARCHAR(100))' );
		$driver->query( "INSERT INTO options VALUES ('rss_123'), ('RSS_456'), ('transient'), ('alpha'), ('ALPS')" );

		$regexp_rows = $driver->query( "SELECT option_name FROM options WHERE option_name REGEXP '^rss_.+$' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'rss_123' ), array( 'option_name' => 'RSS_456' ) ), $regexp_rows );

		$rlike_rows = $driver->query( "SELECT option_name FROM options WHERE option_name RLIKE '^rss_.+$' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'rss_123' ), array( 'option_name' => 'RSS_456' ) ), $rlike_rows );

		$binary_regexp_rows = $driver->query( "SELECT option_name FROM options WHERE option_name REGEXP BINARY '^rss_.+$' ORDER BY option_name" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'rss_123' ) ), $binary_regexp_rows );

		$not_regexp_rows = $driver->query( "SELECT option_name FROM options WHERE option_name NOT REGEXP '^rss_.+$' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'alpha' ), array( 'option_name' => 'ALPS' ), array( 'option_name' => 'transient' ) ), $not_regexp_rows );

		$not_binary_rlike_rows = $driver->query( "SELECT option_name FROM options WHERE option_name NOT RLIKE BINARY '^RSS_.+$' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'alpha' ), array( 'option_name' => 'ALPS' ), array( 'option_name' => 'rss_123' ), array( 'option_name' => 'transient' ) ), $not_binary_rlike_rows );

		$numeric_regexp = $driver->query( "SELECT 123 REGEXP '23$' AS matched" )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'matched' => true ), $numeric_regexp );

		$binary_left_regexp_rows = $driver->query( "SELECT option_name FROM options WHERE BINARY option_name REGEXP '^a' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'alpha' ), array( 'option_name' => 'ALPS' ) ), $binary_left_regexp_rows );

		$binary_left_not_regexp_rows = $driver->query( "SELECT option_name FROM options WHERE BINARY option_name NOT REGEXP '^a' ORDER BY lower(option_name), option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'rss_123' ), array( 'option_name' => 'RSS_456' ), array( 'option_name' => 'transient' ) ), $binary_left_not_regexp_rows );

		$binary_left_qualified_rlike_rows = $driver->query( "SELECT o.option_name FROM options o WHERE BINARY o.option_name RLIKE '^a' ORDER BY lower(o.option_name), o.option_name DESC" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( array( 'option_name' => 'alpha' ), array( 'option_name' => 'ALPS' ) ), $binary_left_qualified_rlike_rows );
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
		$this->assertSame( 2, $replace->rowCount() );
		$this->assertSame( "INSERT OR REPLACE INTO items(id, name, hits) VALUES (1, 'new', 2)", $this->lastDuckDBQuery( $driver ) );

		$replace_without_into = $driver->query( "REPLACE items (id, name) VALUES (2, 'second')" );
		$this->assertSame( 1, $replace_without_into->rowCount() );

		$mixed = $driver->query(
			"REPLACE INTO items (id, name, hits) VALUES
				(1, 'newer', 3),
				(3, 'third', 4)"
		);
		$this->assertSame( 3, $mixed->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'newer',
					'hits' => 3,
				),
				array(
					'id'   => 2,
					'name' => 'second',
					'hits' => 0,
				),
				array(
					'id'   => 3,
					'name' => 'third',
					'hits' => 4,
				),
			),
			$driver->query( 'SELECT id, name, hits FROM items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_replace_values_manual_conflict_counts_deleted_rows_once(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE replace_counts (
				id INTEGER PRIMARY KEY,
				email VARCHAR(100) UNIQUE,
				slug VARCHAR(100) UNIQUE,
				payload VARCHAR(20)
			)'
		);
		$driver->query( "INSERT INTO replace_counts (id, email, slug, payload) VALUES (1, 'a@example.com', 'a', 'old')" );

		$same_row = $driver->query(
			"REPLACE INTO replace_counts (id, email, slug, payload)
			VALUES (1, 'a@example.com', 'a', 'same-row')"
		);
		$this->assertSame( 2, $same_row->rowCount() );

		$two_rows = $driver->query(
			"REPLACE INTO replace_counts (id, email, slug, payload)
			VALUES (2, 'a@example.com', 'b', 'two-rows')"
		);
		$this->assertSame( 2, $two_rows->rowCount() );

		$insert = $driver->query(
			"REPLACE INTO replace_counts (id, email, slug, payload)
			VALUES (3, 'c@example.com', 'c', 'insert')"
		);
		$this->assertSame( 1, $insert->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'      => 2,
					'email'   => 'a@example.com',
					'slug'    => 'b',
					'payload' => 'two-rows',
				),
				array(
					'id'      => 3,
					'email'   => 'c@example.com',
					'slug'    => 'c',
					'payload' => 'insert',
				),
			),
			$driver->query( 'SELECT id, email, slug, payload FROM replace_counts ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_simple_literal_update_reports_changed_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE update_counts (
				id INTEGER PRIMARY KEY,
				title VARCHAR(100),
				status VARCHAR(20),
				hits INTEGER,
				nullable_value VARCHAR(20)
			)'
		);
		$driver->query(
			"INSERT INTO update_counts (id, title, status, hits, nullable_value) VALUES
				(1, 'same', '1', 1, NULL),
				(2, 'other', '1', 2, NULL)"
		);

		$noop = $driver->query( "UPDATE update_counts SET title = 'same', status = '1' WHERE id = 1" );
		$this->assertSame( 0, $noop->rowCount() );

		$changed = $driver->query( "UPDATE update_counts SET title = 'changed', status = '1' WHERE id = 1" );
		$this->assertSame( 1, $changed->rowCount() );

		$mixed = $driver->query( 'UPDATE update_counts SET hits = 2, nullable_value = NULL WHERE id IN (1, 2)' );
		$this->assertSame( 1, $mixed->rowCount() );

		$alias_noop = $driver->query( "UPDATE update_counts AS u SET u.title = 'changed' WHERE u.id = 1" );
		$this->assertSame( 0, $alias_noop->rowCount() );

		$no_match = $driver->query( "UPDATE update_counts SET title = 'missing' WHERE id = 99" );
		$this->assertSame( 0, $no_match->rowCount() );
	}

	public function test_case_only_update_on_case_insensitive_column_reports_changed_row(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wp_users (
				ID bigint(20) unsigned NOT NULL auto_increment,
				user_login varchar(60) NOT NULL default '',
				user_email varchar(100) NOT NULL default '',
				PRIMARY KEY (ID),
				KEY user_email (user_email)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( "INSERT INTO wp_users (ID, user_login, user_email) VALUES (1, 'editor', 'editor@example.com')" );

		$updated = $driver->query( "UPDATE wp_users SET user_email = 'Editor@example.com' WHERE ID = 1" );
		$this->assertSame( 1, $updated->rowCount() );
		$duckdb_queries = $driver->get_last_duckdb_queries();
		$this->assertStringContainsString( 'hex(encode(CAST', end( $duckdb_queries ) );
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'user_email' => 'Editor@example.com',
				),
			),
			$driver->query( 'SELECT ID, user_email FROM wp_users WHERE ID = 1' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$noop = $driver->query( "UPDATE wp_users SET user_email = 'Editor@example.com' WHERE ID = 1" );
		$this->assertSame( 0, $noop->rowCount() );
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
		$this->assertSame( "INSERT INTO \"items\" (id, name, hits) VALUES (1, 'first', 2)", $this->lastDuckDBQuery( $driver ) );

		$inserted_without_into = $driver->query( "INSERT items SET id = 2, name = 'second'" );
		$this->assertSame( 1, $inserted_without_into->rowCount() );

		$ignored = $driver->query( "INSERT IGNORE items SET id = 2, name = 'duplicate'" );
		$this->assertSame( 0, $ignored->rowCount() );
		$this->assertSame( "INSERT OR IGNORE INTO \"items\" (id, name) VALUES (2, 'duplicate')", $this->lastDuckDBQuery( $driver ) );

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

		$driver->query( "INSERT INTO auto_items (name) VALUES ('after-explicit')" );
		$this->assertSame( 43, $driver->get_insert_id() );

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

	public function test_explicit_auto_increment_insert_primes_next_generated_value(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE wptests_terms (
				term_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(200) NOT NULL DEFAULT "",
				slug VARCHAR(200) NOT NULL DEFAULT "",
				term_group BIGINT(10) NOT NULL DEFAULT 0,
				PRIMARY KEY (term_id)
			)'
		);

		$driver->query( "INSERT INTO wptests_terms (term_id, name, slug, term_group) VALUES (1, 'first', 'first', 0)" );
		$this->assertSame( 1, $driver->get_insert_id() );

		$insert = $driver->query( "INSERT INTO wptests_terms (name, slug, term_group) VALUES ('foo', 'bar', 0)" );
		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 2, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'term_id' => 1,
					'name'    => 'first',
				),
				array(
					'term_id' => 2,
					'name'    => 'foo',
				),
			),
			$driver->query( 'SELECT term_id, name FROM wptests_terms ORDER BY term_id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_id_tracks_wordpress_usermeta_shape(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wp_usermeta (
				umeta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			)'
		);

		$insert = $driver->query(
			"INSERT INTO `wp_usermeta` (`user_id`, `meta_key`, `meta_value`)
			VALUES (1, 'wp_persisted_preferences', 'a:0:{}')"
		);

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'umeta_id'   => 1,
					'user_id'    => 1,
					'meta_key'   => 'wp_persisted_preferences',
					'meta_value' => 'a:0:{}',
				),
			),
			$driver->query( 'SELECT umeta_id, user_id, meta_key, meta_value FROM wp_usermeta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 0, $driver->get_insert_id() );
	}

	public function test_insert_id_tracks_prefixed_wordpress_usermeta_shape(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wp_e2e_usermeta (
				umeta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			)'
		);

		$insert = $driver->query(
			"INSERT INTO `wp_e2e_usermeta` (`user_id`, `meta_key`, `meta_value`)
			VALUES (1, 'wp_e2e_persisted_preferences', 'a:0:{}')"
		);

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'umeta_id'   => 1,
					'user_id'    => 1,
					'meta_key'   => 'wp_e2e_persisted_preferences',
					'meta_value' => 'a:0:{}',
				),
			),
			$driver->query( 'SELECT umeta_id, user_id, meta_key, meta_value FROM wp_e2e_usermeta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 0, $driver->get_insert_id() );
	}

	public function test_wordpress_usermeta_omitted_auto_increment_insert_uses_fast_path_returning(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'duckdb-usermeta-insert-' );
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_usermeta_auto_increment_insert_fixture( $setup_driver, 'wptests_usermeta' );
			unset( $setup_driver );

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );
			$queries = array();

			$insert = $driver->query(
				"INSERT INTO `wptests_usermeta` (`user_id`, `meta_key`, `meta_value`)
				VALUES (1, 'wp_persisted_preferences', 'a:1:{s:4:\"note\";s:11:\"hello world\";}')"
			);

			$this->assertSame( 1, $insert->rowCount() );
			$this->assertSame( 1, $driver->get_insert_id() );
			$this->assertLessThan( 4, count( $queries ), implode( "\n", $queries ) );
			$this->assertSame( 1, $this->count_duckdb_table_resolution_queries( $queries ) );
			$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_usermeta' ) );
			$this->assertSame( 0, $this->count_duckdb_auto_increment_metadata_queries( $queries, 'wptests_usermeta' ) );
			$this->assert_wordpress_usermeta_omitted_auto_increment_insert_used_returning( $queries, 'wptests_usermeta' );

			$queries = array();
			$insert  = $driver->query(
				"INSERT INTO wptests_usermeta (user_id, meta_key, meta_value)
				VALUES (2, 'session_tokens', NULL)"
			);

			$this->assertSame( 1, $insert->rowCount() );
			$this->assertSame( 2, $driver->get_insert_id() );
			$this->assertCount( 1, $queries, implode( "\n", $queries ) );
			$this->assert_wordpress_usermeta_omitted_auto_increment_insert_used_returning( $queries, 'wptests_usermeta' );
			$this->assertSame(
				array(
					array(
						'umeta_id'   => 1,
						'user_id'    => 1,
						'meta_key'   => 'wp_persisted_preferences',
						'meta_value' => 'a:1:{s:4:"note";s:11:"hello world";}',
					),
					array(
						'umeta_id'   => 2,
						'user_id'    => 2,
						'meta_key'   => 'session_tokens',
						'meta_value' => null,
					),
				),
				$driver->query( 'SELECT umeta_id, user_id, meta_key, meta_value FROM wptests_usermeta ORDER BY umeta_id' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			@unlink( $path );
		}
	}

	public function test_wordpress_usermeta_omitted_auto_increment_insert_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_usermeta_auto_increment_insert_fixture( $driver, 'wptests_usermeta' );
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_usermeta (
				user_id BIGINT,
				meta_key VARCHAR(255),
				meta_value LONGTEXT
			)'
		);

		$queries = array();
		$insert  = $driver->query(
			"INSERT INTO wptests_usermeta (user_id, meta_key, meta_value)
			VALUES (9, 'shadow', 'temporary')"
		);

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 0, $driver->get_insert_id() );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( ' RETURNING "umeta_id"', $query );
		}
		$this->assertSame(
			array(
				array(
					'user_id'    => 9,
					'meta_key'   => 'shadow',
					'meta_value' => 'temporary',
				),
			),
			$driver->query( 'SELECT user_id, meta_key, meta_value FROM wptests_usermeta' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_usermeta' );
		$this->assertSame(
			array(),
			$driver->query( 'SELECT umeta_id, user_id, meta_key, meta_value FROM wptests_usermeta' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_posts_omitted_auto_increment_insert_uses_cached_metadata_and_returning(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'duckdb-post-insert-' );
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_posts_auto_increment_insert_fixture( $setup_driver );
			unset( $setup_driver );

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );
			$queries = array();

			$insert = $driver->query(
				"INSERT INTO wptests_posts (post_author, post_date, post_title, post_type, post_status)
				VALUES (1, '2026-01-01 00:00:00', 'First', 'post', 'publish')"
			);

			$this->assertSame( 1, $insert->rowCount() );
			$this->assertSame( 1, $driver->get_insert_id() );
			$this->assertLessThan( 12, count( $queries ), implode( "\n", $queries ) );
			$this->assertSame( 0, $this->count_duckdb_auto_increment_metadata_queries( $queries, 'wptests_posts' ) );
			$this->assert_wordpress_posts_omitted_auto_increment_insert_used_returning( $queries, 'ID' );

			$queries = array();
			$insert  = $driver->query(
				"INSERT INTO wptests_posts (post_author, post_date, post_title, post_type, post_status)
				VALUES (2, '2026-01-02 00:00:00', 'Second', 'page', 'draft')"
			);

			$this->assertSame( 1, $insert->rowCount() );
			$this->assertSame( 2, $driver->get_insert_id() );
			$this->assertCount( 1, $queries, implode( "\n", $queries ) );
			$this->assert_wordpress_posts_omitted_auto_increment_insert_used_returning( $queries, 'ID' );
			$this->assertSame(
				array(
					array(
						'ID'          => 1,
						'post_author' => 1,
						'post_title'  => 'First',
					),
					array(
						'ID'          => 2,
						'post_author' => 2,
						'post_title'  => 'Second',
					),
				),
				$driver->query( 'SELECT ID, post_author, post_title FROM wptests_posts ORDER BY ID' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			@unlink( $path );
		}
	}

	public function test_wordpress_posts_omitted_auto_increment_insert_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_posts_auto_increment_insert_fixture( $driver );
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				temp_title VARCHAR(100) NOT NULL,
				PRIMARY KEY (ID)
			)'
		);

		$queries = array();
		$insert  = $driver->query( "INSERT INTO wptests_posts (temp_title) VALUES ('temporary')" );

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$this->assert_wordpress_posts_omitted_auto_increment_insert_used_returning( $queries, 'ID' );
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'temp_title' => 'temporary',
				),
			),
			$driver->query( 'SELECT ID, temp_title FROM wptests_posts' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_posts' );
		$this->assertSame(
			array(),
			$driver->query( 'SELECT ID, post_title FROM wptests_posts' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$insert  = $driver->query(
			"INSERT INTO wptests_posts (post_author, post_date, post_title, post_type, post_status)
			VALUES (10, '2026-01-10 00:00:00', 'persistent', 'post', 'publish')"
		);

		$this->assertSame( 1, $insert->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$this->assert_wordpress_posts_omitted_auto_increment_insert_used_returning( $queries, 'ID' );
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'post_title' => 'persistent',
				),
			),
			$driver->query( 'SELECT ID, post_title FROM wptests_posts' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_ignore_explicit_auto_increment_insert_id_skips_ignored_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE ignore_primary_before (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) UNIQUE
			)'
		);
		$driver->query(
			'CREATE TABLE ignore_primary_after (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) UNIQUE
			)'
		);
		$driver->query(
			'CREATE TABLE ignore_secondary_after (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) UNIQUE
			)'
		);

		$driver->query( "INSERT INTO ignore_primary_before (id, name) VALUES (3, 'existing')" );
		$inserted_before_ignored = $driver->query(
			"INSERT IGNORE INTO ignore_primary_before (id, name)
			VALUES (2, 'inserted'), (3, 'ignored-primary')"
		);
		$this->assertSame( 1, $inserted_before_ignored->rowCount() );
		$this->assertSame( 2, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'id'   => 2,
					'name' => 'inserted',
				),
				array(
					'id'   => 3,
					'name' => 'existing',
				),
			),
			$driver->query( 'SELECT id, name FROM ignore_primary_before ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "INSERT INTO ignore_primary_after (id, name) VALUES (1, 'existing')" );
		$inserted_after_ignored = $driver->query(
			"INSERT IGNORE INTO ignore_primary_after (id, name)
			VALUES (4, 'inserted'), (1, 'ignored-primary')"
		);
		$this->assertSame( 1, $inserted_after_ignored->rowCount() );
		$this->assertSame( 4, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'existing',
				),
				array(
					'id'   => 4,
					'name' => 'inserted',
				),
			),
			$driver->query( 'SELECT id, name FROM ignore_primary_after ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$driver->query( "INSERT INTO ignore_primary_after (name) VALUES ('generated-after-ignore')" );
		$this->assertSame( 5, $driver->get_insert_id() );

		$driver->query( "INSERT INTO ignore_secondary_after (id, name) VALUES (1, 'taken')" );
		$inserted_before_secondary_conflict = $driver->query(
			"INSERT IGNORE INTO ignore_secondary_after (id, name)
			VALUES (5, 'inserted'), (6, 'taken')"
		);
		$this->assertSame( 1, $inserted_before_secondary_conflict->rowCount() );
		$this->assertSame( 5, $driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'taken',
				),
				array(
					'id'   => 5,
					'name' => 'inserted',
				),
			),
			$driver->query( 'SELECT id, name FROM ignore_secondary_after ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_ignore_omitted_auto_increment_insert_id_skips_ignored_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE ignore_generated (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(100) UNIQUE
			)'
		);

		$driver->query( "INSERT INTO ignore_generated (name) VALUES ('existing')" );
		$ignored = $driver->query( "INSERT IGNORE INTO ignore_generated (name) VALUES ('existing')" );
		$this->assertSame( 0, $ignored->rowCount() );
		$this->assertSame( 0, $driver->get_insert_id() );

		$mixed = $driver->query(
			"INSERT IGNORE INTO ignore_generated (name)
			VALUES ('inserted-before'), ('existing'), ('inserted-after')"
		);
		$this->assertSame( 2, $mixed->rowCount() );
		$this->assertSame( 5, $driver->get_insert_id() );
		$mixed_duckdb_queries = $driver->get_last_duckdb_queries();
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'existing',
				),
				array(
					'id'   => 3,
					'name' => 'inserted-before',
				),
				array(
					'id'   => 5,
					'name' => 'inserted-after',
				),
			),
			$driver->query( 'SELECT id, name FROM ignore_generated ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( ' RETURNING "id"', end( $mixed_duckdb_queries ) );
		foreach ( $mixed_duckdb_queries as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'SELECT MAX', $duckdb_sql );
		}
	}

	public function test_serial_alias_tracks_generated_insert_id_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE serial_items (id SERIAL, name VARCHAR(20))' );

		$driver->query( "INSERT INTO serial_items (name) VALUES ('first')" );
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->query( "INSERT INTO serial_items (name) VALUES ('second')" );
		$this->assertSame( 2, $driver->get_insert_id() );

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
			$driver->query( 'SELECT id, name FROM serial_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				'Field'   => 'id',
				'Type'    => 'bigint unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			),
			$driver->query( 'SHOW COLUMNS FROM serial_items WHERE Field = "id"' )->fetch( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_id_tracks_on_duplicate_key_update_policy(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE odku_auto_items (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				slug VARCHAR(100) UNIQUE,
				alias VARCHAR(100) UNIQUE,
				hits INTEGER NOT NULL DEFAULT 0
			)'
		);

		$driver->query( "INSERT INTO odku_auto_items (slug, alias, hits) VALUES ('existing', 'alias-existing', 1)" );
		$this->assertSame( 1, $driver->get_insert_id() );

		$driver->query(
			"INSERT INTO odku_auto_items (slug, alias, hits)
			VALUES ('generated', 'alias-generated', 2)
			ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
		);
		$this->assertSame( 2, $driver->get_insert_id() );

		$driver->query(
			"INSERT INTO odku_auto_items (id, slug, alias, hits)
			VALUES (42, 'explicit', 'alias-explicit', 4)
			ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
		);
		$this->assertSame( 42, $driver->get_insert_id() );

		$driver->query(
			"INSERT INTO odku_auto_items (id, slug, alias, hits)
			VALUES (1, 'primary-change', 'alias-primary-change', 7)
			ON DUPLICATE KEY UPDATE slug = VALUES(slug), alias = VALUES(alias), hits = VALUES(hits)"
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$driver->query(
			"INSERT INTO odku_auto_items (slug, alias, hits)
			VALUES ('primary-change', 'alias-unused-update', 3)
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)"
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$driver->query(
			"INSERT INTO odku_auto_items (slug, alias, hits)
			VALUES ('primary-change', 'alias-unused-noop', 99)
			ON DUPLICATE KEY UPDATE hits = hits"
		);
		$this->assertSame( 0, $driver->get_insert_id() );

		$driver->query( "INSERT INTO odku_auto_items (id, slug, alias) VALUES (100, 'before-failure', 'alias-before-failure')" );
		$this->assertSame( 100, $driver->get_insert_id() );

		try {
			$driver->query(
				"INSERT INTO odku_auto_items (slug, alias, hits)
				VALUES ('primary-change', 'alias-generated', 13)
				ON DUPLICATE KEY UPDATE alias = VALUES(alias)"
			);
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame( 0, $driver->get_insert_id() );
			$this->assertSame(
				array(
					array(
						'id'    => 1,
						'slug'  => 'primary-change',
						'alias' => 'alias-primary-change',
						'hits'  => 10,
					),
					array(
						'id'    => 2,
						'slug'  => 'generated',
						'alias' => 'alias-generated',
						'hits'  => 2,
					),
					array(
						'id'    => 42,
						'slug'  => 'explicit',
						'alias' => 'alias-explicit',
						'hits'  => 4,
					),
					array(
						'id'    => 100,
						'slug'  => 'before-failure',
						'alias' => 'alias-before-failure',
						'hits'  => 0,
					),
				),
				$driver->query( 'SELECT id, slug, alias, hits FROM odku_auto_items ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
			);
			return;
		}

		$this->fail( 'Expected duplicate ODKU update to violate the alias unique key.' );
	}

	public function test_wordpress_options_on_duplicate_key_update_insert_branch_uses_returning(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);

		$inserted = $driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('_transient_runtime_probe', 'created', 'off')
			ON DUPLICATE KEY UPDATE option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$this->assertSame( 1, $inserted->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$insert_queries = $driver->get_last_duckdb_queries();
		$insert_log     = implode( "\n", $insert_queries );
		$this->assertStringContainsString( 'ON CONFLICT ("option_name") DO UPDATE', $insert_log );
		$this->assertStringContainsString( ' RETURNING "option_id"', end( $insert_queries ) );
		foreach ( $insert_queries as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'SELECT MAX("option_id")', $duckdb_sql );
			$this->assertStringNotContainsString( 'SELECT currval(', $duckdb_sql );
		}

		$updated = $driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('_transient_runtime_probe', 'updated', 'yes')
			ON DUPLICATE KEY UPDATE option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$this->assertSame( 1, $updated->rowCount() );
		$this->assertSame( 0, $driver->get_insert_id() );
		$update_queries = $driver->get_last_duckdb_queries();
		$update_log     = implode( "\n", $update_queries );
		$this->assertStringContainsString( 'ON CONFLICT ("option_name") DO UPDATE', $update_log );
		$this->assertStringNotContainsString( ' RETURNING "option_id"', $update_log );
		foreach ( $update_queries as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'SELECT MAX("option_id")', $duckdb_sql );
			$this->assertStringNotContainsString( 'SELECT currval(', $duckdb_sql );
		}

		$this->assertSame(
			array(
				array(
					'option_id'    => 1,
					'option_name'  => '_transient_runtime_probe',
					'option_value' => 'updated',
					'autoload'     => 'yes',
				),
			),
			$driver->query( 'SELECT option_id, option_name, option_value, autoload FROM wptests_options' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_options_on_duplicate_key_update_insert_branch_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);
		$driver->query(
			"CREATE TEMPORARY TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);

		$inserted = $driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('_transient_temp_probe', 'temporary', 'yes')
			ON DUPLICATE KEY UPDATE option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$this->assertSame( 1, $inserted->rowCount() );
		$this->assertSame( 1, $driver->get_insert_id() );
		$insert_queries = $driver->get_last_duckdb_queries();
		$insert_log     = implode( "\n", $insert_queries );
		$this->assertStringContainsString( 'ON CONFLICT ("option_name") DO UPDATE', $insert_log );
		$this->assertStringContainsString( ' RETURNING "option_id"', end( $insert_queries ) );
		foreach ( $insert_queries as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'SELECT MAX("option_id")', $duckdb_sql );
			$this->assertStringNotContainsString( 'SELECT currval(', $duckdb_sql );
		}
		$this->assertSame(
			array(
				array(
					'option_id'    => 1,
					'option_name'  => '_transient_temp_probe',
					'option_value' => 'temporary',
					'autoload'     => 'yes',
				),
			),
			$driver->query( 'SELECT option_id, option_name, option_value, autoload FROM wptests_options' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_options' );
		$this->assertSame(
			array(),
			$driver->query( 'SELECT option_id, option_name, option_value, autoload FROM wptests_options' )->fetchAll( PDO::FETCH_ASSOC )
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
			'INSERT INTO items(id, name, hits) VALUES (1, \'renamed\', 7) ON CONFLICT ("id") DO UPDATE SET name = CAST((excluded."name") AS VARCHAR), hits = excluded."hits"',
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

	public function test_insert_on_duplicate_key_update_multirow_term_relationships_is_emulated(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_term_relationships (
				object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				term_order INT(11) NOT NULL DEFAULT '0',
				PRIMARY KEY (object_id, term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order)
			VALUES (1, 11, 0), (2, 11, 0)'
		);

		$result = $driver->query(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order)
			VALUES (1, 11, 7), (1, 12, 0), (3, 11, 0)
			ON DUPLICATE KEY UPDATE term_order = VALUES(term_order)'
		);

		$this->assertSame( 3, $result->rowCount() );
		$this->assertStringContainsString(
			'ON CONFLICT ("object_id", "term_taxonomy_id") DO UPDATE SET term_order = excluded."term_order"',
			$this->lastDuckDBQuery( $driver )
		);
		$this->assertSame(
			array(
				array(
					'object_id'        => 1,
					'term_taxonomy_id' => 11,
					'term_order'       => 7,
				),
				array(
					'object_id'        => 1,
					'term_taxonomy_id' => 12,
					'term_order'       => 0,
				),
				array(
					'object_id'        => 2,
					'term_taxonomy_id' => 11,
					'term_order'       => 0,
				),
				array(
					'object_id'        => 3,
					'term_taxonomy_id' => 11,
					'term_order'       => 0,
				),
			),
			$driver->query( 'SELECT object_id, term_taxonomy_id, term_order FROM wptests_term_relationships ORDER BY object_id, term_taxonomy_id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_on_duplicate_key_update_multirow_rejects_mixed_conflict_targets(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE multi_unique (
				id INTEGER PRIMARY KEY,
				slug VARCHAR(100) UNIQUE,
				hits INTEGER NOT NULL DEFAULT 0
			)'
		);
		$driver->query( "INSERT INTO multi_unique (id, slug, hits) VALUES (1, 'one', 1), (2, 'two', 2)" );

		try {
			$driver->query(
				"INSERT INTO multi_unique (id, slug, hits)
				VALUES (1, 'new-one', 7), (3, 'two', 9)
				ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
			);
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame(
				'Unsupported INSERT ... ON DUPLICATE KEY UPDATE statement in DuckDB driver. Insert values match multiple unique key targets.',
				$e->getMessage()
			);
			return;
		}

		$this->fail( 'Expected mixed-target multi-row ODKU to be rejected.' );
	}

	public function test_insert_on_duplicate_key_update_preserves_serialized_nul_payloads(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);

		$payload = serialize(
			array(
				"\0*\0data" => "line\n<iframe class='youtube-player' rel=\"https://api.w.org/\">",
			)
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('_transient_feed_mod_example', " . $this->mysql_single_quoted_literal( $payload ) . ", 'off')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name),
				option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$duckdb_queries = $driver->get_last_duckdb_queries();
		$this->assertNotEmpty(
			array_filter(
				$duckdb_queries,
				static function ( string $sql ): bool {
					return false !== strpos( $sql, 'chr(0)' );
				}
			)
		);
		$stored_payload = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = '_transient_feed_mod_example'" )->fetchColumn();
		$this->assertSame( bin2hex( $payload ), bin2hex( $stored_payload ) );
		$this->assertSame(
			$payload,
			$stored_payload
		);

		$updated_payload = serialize(
			array(
				"\0*\0data" => "line\n<iframe class='youtube-player' rel=\"https://api.w.org/\">",
				'updated'   => "tail\0value",
			)
		);
		$updated         = $driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('_transient_feed_mod_example', " . $this->mysql_single_quoted_literal( $updated_payload ) . ", 'off')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name),
				option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$this->assertSame( 1, $updated->rowCount() );
		$stored_updated_payload = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = '_transient_feed_mod_example'" )->fetchColumn();
		$this->assertSame( bin2hex( $updated_payload ), bin2hex( $stored_updated_payload ) );
		$this->assertSame(
			$updated_payload,
			$stored_updated_payload
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
		$this->assertSame( 2, $replaced->rowCount() );
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
		$this->assertSame( 2, $replaced->rowCount() );
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

	public function test_mysql_fulltext_spatial_and_show_index_where_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE wp_search (
				id BIGINT(20) UNSIGNED NOT NULL,
				content LONGTEXT NOT NULL,
				shape GEOMETRYCOLLECTION NOT NULL,
				KEY a_key (content(191)),
				FULLTEXT KEY content_fulltext (content),
				SPATIAL KEY shape_spatial (shape)
			)'
		);

		$indexes      = $driver->query( 'SHOW INDEX FROM wp_search' )->fetchAll( PDO::FETCH_ASSOC );
		$index_types  = array_column( $indexes, 'Index_type', 'Key_name' );
		$index_prefix = array_column( $indexes, 'Sub_part', 'Key_name' );

		$this->assertSame( 'BTREE', $index_types['a_key'] );
		$this->assertSame( 'FULLTEXT', $index_types['content_fulltext'] );
		$this->assertSame( 'SPATIAL', $index_types['shape_spatial'] );
		$this->assertSame( 191, $index_prefix['a_key'] );

		$filtered = $driver->query( "SHOW INDEXES FROM wp_search WHERE Key_name='a_key';" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'a_key', $filtered[0]['Key_name'] );
		$this->assertSame( 191, $filtered[0]['Sub_part'] );

		$create_table = $driver->query( 'SHOW CREATE TABLE wp_search' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( 'FULLTEXT KEY `content_fulltext` (`content`)', $create_table['Create Table'] );
		$this->assertStringContainsString( 'SPATIAL KEY `shape_spatial` (`shape`)', $create_table['Create Table'] );
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

	public function test_alter_table_add_unique_constraint_updates_metadata_and_enforces_uniqueness(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE alter_unique_add (
				id INT PRIMARY KEY,
				name VARCHAR(50),
				slug VARCHAR(50)
			)'
		);
		$driver->query( "INSERT INTO alter_unique_add (id, name, slug) VALUES (1, 'first', 'a'), (2, 'second', 'b')" );

		$result = $driver->query( 'ALTER TABLE alter_unique_add ADD CONSTRAINT name_unique UNIQUE (name)' );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertSame(
			array( 'PRIMARY', 'name_unique' ),
			array_column( $driver->query( 'SHOW INDEX FROM alter_unique_add' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array(
				'id'   => 'PRI',
				'name' => 'UNI',
				'slug' => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM alter_unique_add' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);

		$show_create = $driver->query( 'SHOW CREATE TABLE alter_unique_add' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( 'UNIQUE KEY `name_unique` (`name`)', $show_create['Create Table'] );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'name_unique',
					'CONSTRAINT_TYPE' => 'UNIQUE',
					'ENFORCED'        => 'YES',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_add'
					AND constraint_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'INDEX_NAME'   => 'name_unique',
					'NON_UNIQUE'   => 0,
					'SEQ_IN_INDEX' => 1,
					'COLUMN_NAME'  => 'name',
				),
			),
			$driver->query(
				"SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_add'
					AND index_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'  => 'name_unique',
					'COLUMN_NAME'      => 'name',
					'ORDINAL_POSITION' => 1,
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_add'
					AND constraint_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( "INSERT INTO alter_unique_add (id, name, slug) VALUES (3, 'first', 'duplicate')" );
			$this->fail( 'Expected duplicate INSERT to fail after ADD UNIQUE constraint.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		try {
			$driver->query( "UPDATE alter_unique_add SET name = 'first' WHERE id = 2" );
			$this->fail( 'Expected duplicate UPDATE to fail after ADD UNIQUE constraint.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB UPDATE', $e->getMessage() );
		}
	}

	public function test_alter_table_add_composite_unique_constraint_updates_key_column_usage(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE alter_unique_composite (
				tenant_id INT,
				slug VARCHAR(50),
				label VARCHAR(50)
			)'
		);
		$driver->query( "INSERT INTO alter_unique_composite (tenant_id, slug, label) VALUES (1, 'home', 'Home'), (1, 'about', 'About')" );

		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_unique_composite ADD CONSTRAINT tenant_slug_unique UNIQUE (tenant_id, slug)' )->rowCount()
		);

		$statistics = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
			FROM information_schema.statistics
			WHERE table_schema = 'wp'
				AND table_name = 'alter_unique_composite'
				AND index_name = 'tenant_slug_unique'
			ORDER BY seq_in_index"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'tenant_id', 'slug' ), array_column( $statistics, 'COLUMN_NAME' ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $statistics, 'SEQ_IN_INDEX' ) ) );
		$this->assertSame( array( 0, 0 ), array_map( 'intval', array_column( $statistics, 'NON_UNIQUE' ) ) );

		$key_column_usage = $driver->query(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name = 'alter_unique_composite'
				AND constraint_name = 'tenant_slug_unique'
			ORDER BY ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'tenant_id', 'slug' ), array_column( $key_column_usage, 'COLUMN_NAME' ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $key_column_usage, 'ORDINAL_POSITION' ) ) );
	}

	public function test_alter_table_add_unique_constraint_failures_do_not_mutate_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE alter_unique_guard (id INT PRIMARY KEY, name VARCHAR(50), slug VARCHAR(50))' );
		$driver->query( "INSERT INTO alter_unique_guard (id, name, slug) VALUES (1, 'same', 'a'), (2, 'same', 'b')" );

		$before = $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_guard' );
		try {
			$driver->query( 'ALTER TABLE alter_unique_guard ADD CONSTRAINT name_unique UNIQUE (name)' );
			$this->fail( 'Expected ADD UNIQUE constraint to reject duplicate existing values.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to create DuckDB UNIQUE constraint index', $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_guard' ) );

		try {
			$driver->query( 'ALTER TABLE alter_unique_guard ADD CONSTRAINT missing_unique UNIQUE (missing_column)' );
			$this->fail( 'Expected ADD UNIQUE constraint to reject unknown columns.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Unknown column 'missing_column'", $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_guard' ) );
	}

	public function test_alter_table_add_unique_constraint_name_precedence_and_duplicate_names(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE alter_unique_precedence (id INT PRIMARY KEY, name VARCHAR(50))' );
		$driver->query( "INSERT INTO alter_unique_precedence (id, name) VALUES (1, 'first'), (2, 'second')" );

		$driver->query( 'ALTER TABLE alter_unique_precedence ADD CONSTRAINT constraint_name UNIQUE KEY index_name (name)' );
		$this->assertSame(
			array( 'PRIMARY', 'index_name' ),
			array_column( $driver->query( 'SHOW INDEX FROM alter_unique_precedence' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'index_name',
					'CONSTRAINT_TYPE' => 'UNIQUE',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_precedence'
					AND constraint_type = 'UNIQUE'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'ALTER TABLE alter_unique_precedence DROP CONSTRAINT constraint_name' );
			$this->fail( 'Expected DROP CONSTRAINT to use the explicit index name, not the constraint label.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Unknown constraint 'constraint_name'", $e->getMessage() );
		}

		$before = $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_precedence' );
		try {
			$driver->query( 'ALTER TABLE alter_unique_precedence ADD CONSTRAINT other_name UNIQUE KEY index_name (name)' );
			$this->fail( 'Expected ADD UNIQUE constraint to reject duplicate explicit index names.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Duplicate key name 'index_name'", $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_precedence' ) );
	}

	public function test_alter_table_drop_unique_constraint_removes_metadata_and_enforcement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE alter_unique_drop (id INT PRIMARY KEY, name VARCHAR(50))' );
		$driver->query( "INSERT INTO alter_unique_drop (id, name) VALUES (1, 'first'), (2, 'second')" );
		$driver->query( 'ALTER TABLE alter_unique_drop ADD CONSTRAINT name_unique UNIQUE (name)' );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_unique_drop DROP CONSTRAINT name_unique' )->rowCount() );
		$this->assertSame(
			array( 'PRIMARY' ),
			array_column( $driver->query( 'SHOW INDEX FROM alter_unique_drop' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);
		$this->assertSame(
			array(
				'id'   => 'PRI',
				'name' => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM alter_unique_drop' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_drop'
					AND constraint_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT INDEX_NAME
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'alter_unique_drop'
					AND index_name = 'name_unique'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 1, $driver->query( "INSERT INTO alter_unique_drop (id, name) VALUES (3, 'first')" )->rowCount() );
	}

	public function test_alter_table_drop_unique_constraint_missing_and_ambiguous_names_do_not_mutate(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query(
			'CREATE TABLE alter_unique_ambiguous (
				id INT PRIMARY KEY,
				name VARCHAR(50),
				CONSTRAINT shared_name CHECK (id >= 0)
			)'
		);
		$driver->query( "INSERT INTO alter_unique_ambiguous (id, name) VALUES (1, 'first'), (2, 'second')" );
		$driver->query( 'ALTER TABLE alter_unique_ambiguous ADD CONSTRAINT shared_name UNIQUE (name)' );

		$before = $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_ambiguous' );
		try {
			$driver->query( 'ALTER TABLE alter_unique_ambiguous DROP CONSTRAINT missing_name' );
			$this->fail( 'Expected DROP CONSTRAINT missing name to reject.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Unknown constraint 'missing_name'", $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_ambiguous' ) );

		try {
			$driver->query( 'ALTER TABLE alter_unique_ambiguous DROP CONSTRAINT shared_name' );
			$this->fail( 'Expected DROP CONSTRAINT with cross-type duplicate names to be ambiguous.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Ambiguous constraint 'shared_name'", $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_unique_constraint_snapshot( $driver, 'alter_unique_ambiguous' ) );
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

	public function test_information_schema_columns_exact_name_materializes_only_matching_table(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infocolumnsexactplain (id INT, name VARCHAR(20), notes TEXT)' );
		$driver->query( 'CREATE TABLE infocolumnsexactnoise (noise_id INT, marker VARCHAR(20))' );
		$driver->query( 'CREATE TABLE infocolumnsexactother (other_id INT)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT COLUMN_NAME, DATA_TYPE
				FROM information_schema.columns
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infocolumnsexactplain'
				ORDER BY ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'id', 'name', 'notes' ), array_column( $rows, 'COLUMN_NAME' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactplain' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactnoise' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactother' ) );

		$queries = array();
		$missing = $driver->query(
			"SELECT COLUMN_NAME
				FROM information_schema.columns
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infocolumnsexactmissing'
				ORDER BY ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $missing );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactplain' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactnoise' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsexactother' ) );

		$driver->query( 'CREATE TABLE infocolumnsin_options (option_id INT, option_name VARCHAR(191), option_value LONGTEXT)' );
		$driver->query( 'CREATE TABLE infocolumnsin_terms (term_id INT, name VARCHAR(200))' );
		$driver->query( 'CREATE TABLE infocolumnsin_noise (noise_id INT)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, COLUMN_NAME
				FROM information_schema.columns AS c
				WHERE c.TABLE_SCHEMA = 'wp'
					AND c.TABLE_NAME IN ('infocolumnsin_options','infocolumnsin_terms')
				ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'infocolumnsin_options',
				'infocolumnsin_options',
				'infocolumnsin_options',
				'infocolumnsin_terms',
				'infocolumnsin_terms',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsin_options' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsin_terms' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsin_noise' ) );
	}

	public function test_information_schema_columns_name_collation_fast_path_uses_metadata_without_stage_rebuild(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_termmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY term_id (term_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( 'CREATE TABLE wptests_metadata_noise (noise_id INT, note VARCHAR(20))' );

		$sql     = "SELECT COLUMN_NAME, COLLATION_NAME
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name IN ('wptests_postmeta', 'wptests_termmeta')
			ORDER BY TABLE_NAME, COLUMN_NAME";
		$queries = array();
		$rows    = $driver->query( $sql )->fetchAll( PDO::FETCH_ASSOC );

		$expected = array(
			array(
				'COLUMN_NAME'    => 'meta_id',
				'COLLATION_NAME' => null,
			),
			array(
				'COLUMN_NAME'    => 'meta_key',
				'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
			),
			array(
				'COLUMN_NAME'    => 'meta_value',
				'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
			),
			array(
				'COLUMN_NAME'    => 'post_id',
				'COLLATION_NAME' => null,
			),
			array(
				'COLUMN_NAME'    => 'meta_id',
				'COLLATION_NAME' => null,
			),
			array(
				'COLUMN_NAME'    => 'meta_key',
				'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
			),
			array(
				'COLUMN_NAME'    => 'meta_value',
				'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
			),
			array(
				'COLUMN_NAME'    => 'term_id',
				'COLLATION_NAME' => null,
			),
		);

		$this->assertSame( $expected, $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_columns_stage_queries( $queries ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_postmeta' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_termmeta' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_metadata_noise' ) );
		$this->assertSame(
			array( array( 'found_rows' => 8 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$this->assertSame( $expected, $driver->query( $sql )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $queries );
	}

	public function test_information_schema_columns_name_collation_fast_path_preserves_schema_missing_case_and_temp_shadow_semantics(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_shadowmeta (
				persistent_id BIGINT(20) UNSIGNED NOT NULL,
				persistent_name VARCHAR(50) NOT NULL DEFAULT ''
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$queries = array();
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT COLUMN_NAME, COLLATION_NAME
				FROM information_schema.columns
				WHERE table_schema = 'not_wp'
					AND table_name IN ('wptests_shadowmeta')
				ORDER BY TABLE_NAME, COLUMN_NAME"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $queries );

		$queries = array();
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'    => 'persistent_id',
					'COLLATION_NAME' => null,
				),
				array(
					'COLUMN_NAME'    => 'persistent_name',
					'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
				),
			),
			$driver->query(
				"SELECT `column_name`, `collation_name`
				FROM `information_schema`.`columns`
				WHERE 'wp' = `table_schema`
					AND `table_name` IN ('wptests_shadowmeta', 'wptests_missingmeta')
				ORDER BY `table_name`, `column_name`"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_columns_stage_queries( $queries ) );

		$driver->query(
			"CREATE TEMPORARY TABLE wptests_shadowmeta (
				temp_id BIGINT(20) UNSIGNED NOT NULL,
				temp_name VARCHAR(50) NOT NULL DEFAULT ''
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$queries = array();
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'    => 'persistent_id',
					'COLLATION_NAME' => null,
				),
				array(
					'COLUMN_NAME'    => 'persistent_name',
					'COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, COLLATION_NAME
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name IN ('wptests_shadowmeta')
				ORDER BY TABLE_NAME, COLUMN_NAME"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( array(), $queries );

		$show_columns = $driver->query( 'SHOW FULL COLUMNS FROM wptests_shadowmeta' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'temp_id', 'temp_name' ), array_column( $show_columns, 'Field' ) );
	}

	public function test_information_schema_columns_wildcard_and_complex_predicates_fall_back_to_full_refresh(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infocolumnswildplain (id INT, name VARCHAR(20))' );
		$driver->query( 'CREATE TABLE infocolumnswildnoise (noise_id INT, marker VARCHAR(20))' );
		$driver->query( 'CREATE TABLE infocolumnswildother (other_id INT)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, COLUMN_NAME
				FROM information_schema.columns
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME LIKE 'infocolumnswild%'
				ORDER BY TABLE_NAME, ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'infocolumnswildnoise',
				'infocolumnswildnoise',
				'infocolumnswildother',
				'infocolumnswildplain',
				'infocolumnswildplain',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnswildplain' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnswildnoise' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnswildother' ) );

		$driver->query( 'CREATE TABLE infocolumnsfuncplain (id INT, name VARCHAR(20))' );
		$driver->query( 'CREATE TABLE infocolumnsfuncnoise (noise_id INT, marker VARCHAR(20))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, COLUMN_NAME
				FROM information_schema.columns
				WHERE TABLE_SCHEMA = 'wp'
					AND LOWER(TABLE_NAME) = 'infocolumnsfuncplain'
				ORDER BY TABLE_NAME, ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'infocolumnsfuncplain', 'infocolumnsfuncplain' ), array_column( $rows, 'TABLE_NAME' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsfuncplain' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infocolumnsfuncnoise' ) );

		$driver->query( 'CREATE TABLE infocolumnsfastfallbackplain (id INT, name VARCHAR(20))' );
		$driver->query( 'CREATE TABLE infocolumnsfastfallbacknoise (noise_id INT, marker VARCHAR(20))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT COLUMN_NAME, COLLATION_NAME
				FROM information_schema.columns
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME LIKE 'infocolumnsfastfallback%'
				ORDER BY TABLE_NAME, COLUMN_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'marker', 'noise_id', 'id', 'name' ), array_column( $rows, 'COLUMN_NAME' ) );
		$this->assertGreaterThan( 0, $this->count_duckdb_information_schema_columns_stage_queries( $queries ) );
	}

	public function test_create_table_charset_metadata_tracks_table_and_column_declarations(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE charset_metadata (
				id INT,
				ascii_default VARCHAR(10),
				big5_col VARCHAR(50) CHARACTER SET big5,
				koi8r_col TEXT CHARACTER SET koi8r,
				utf8_col VARCHAR(50) CHARSET utf8,
				utf8mb4_col VARCHAR(50) CHARACTER SET utf8mb4,
				binary_col BINARY,
				blob_col BLOB
			) DEFAULT CHARSET=ascii'
		);
		$driver->query(
			'CREATE TABLE charset_collate_metadata (
				name VARCHAR(10),
				explicit_name VARCHAR(10) COLLATE utf8mb4_unicode_ci
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query( 'ALTER TABLE charset_collate_metadata ADD COLUMN added_name VARCHAR(10)' );

		$this->assertSame(
			array(
				'id'            => null,
				'ascii_default' => 'ascii_general_ci',
				'big5_col'      => 'big5_chinese_ci',
				'koi8r_col'     => 'koi8r_general_ci',
				'utf8_col'      => 'utf8_general_ci',
				'utf8mb4_col'   => 'utf8mb4_0900_ai_ci',
				'binary_col'    => null,
				'blob_col'      => null,
			),
			array_column(
				$driver->query( 'SHOW FULL COLUMNS FROM charset_metadata' )->fetchAll( PDO::FETCH_ASSOC ),
				'Collation',
				'Field'
			)
		);
		$this->assertSame(
			array(
				array(
					'TABLE_COLLATION' => 'utf8mb4_unicode_ci',
				),
				array(
					'TABLE_COLLATION' => 'ascii_general_ci',
				),
			),
			$driver->query(
				"SELECT table_collation
				FROM information_schema.tables
				WHERE table_schema = 'wp'
					AND table_name IN ('charset_metadata', 'charset_collate_metadata')
				ORDER BY table_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'        => 'name',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'COLLATION_NAME'     => 'utf8mb4_0900_ai_ci',
				),
				array(
					'COLUMN_NAME'        => 'explicit_name',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'COLLATION_NAME'     => 'utf8mb4_unicode_ci',
				),
				array(
					'COLUMN_NAME'        => 'added_name',
					'CHARACTER_SET_NAME' => 'utf8mb4',
					'COLLATION_NAME'     => 'utf8mb4_0900_ai_ci',
				),
			),
			$driver->query(
				"SELECT column_name, character_set_name, collation_name
				FROM information_schema.columns
				WHERE table_schema = 'wp'
					AND table_name = 'charset_collate_metadata'
				ORDER BY ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE charset_metadata' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringContainsString( 'DEFAULT CHARSET=ascii COLLATE=ascii_general_ci', $create_sql );
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

	public function test_information_schema_statistics_exact_name_materializes_only_matching_table(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infostatsexactplain (id INT NOT NULL, name VARCHAR(20), KEY name_key (name))' );
		$driver->query( 'CREATE TABLE infostatsexactnoise (noise_id INT NOT NULL, marker VARCHAR(20), KEY marker_key (marker))' );
		$driver->query( 'CREATE TABLE infostatsexactother (other_id INT NOT NULL, KEY other_key (other_id))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infostatsexactplain'
				ORDER BY INDEX_NAME, SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'name_key' ), array_column( $rows, 'INDEX_NAME' ) );
		$this->assertSame( array( 'name' ), array_column( $rows, 'COLUMN_NAME' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactplain' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactnoise' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactother' ) );

		$queries = array();
		$missing = $driver->query(
			"SELECT INDEX_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infostatsexactmissing'
				ORDER BY INDEX_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $missing );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactplain' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactnoise' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactother' ) );

		$queries      = array();
		$wrong_schema = $driver->query(
			"SELECT INDEX_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'not_wp'
					AND TABLE_NAME = 'infostatsexactplain'
				ORDER BY INDEX_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $wrong_schema );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactplain' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactnoise' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsexactother' ) );

		$driver->query( 'CREATE TABLE infostatsin_options (option_id INT, option_name VARCHAR(191), KEY option_name_key (option_name))' );
		$driver->query( 'CREATE TABLE infostatsin_terms (term_id INT, name VARCHAR(200), KEY name_key (name))' );
		$driver->query( 'CREATE TABLE infostatsin_noise (noise_id INT, KEY noise_key (noise_id))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
				FROM information_schema.statistics AS s
				WHERE s.TABLE_SCHEMA = 'wp'
					AND s.TABLE_NAME IN ('infostatsin_options','infostatsin_terms')
				ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'infostatsin_options',
				'infostatsin_terms',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( array( 'option_name_key', 'name_key' ), array_column( $rows, 'INDEX_NAME' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsin_options' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsin_terms' ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsin_noise' ) );
	}

	public function test_information_schema_statistics_exact_projection_fast_path_skips_stage_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_statistics_projection_fixture( $driver );

		$queries   = array();
		$statement = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name IN ('wptests_postmeta','wptests_termmeta','wptests_commentmeta')
				ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
		);
		$rows      = $statement->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array( 'comment_id', 'meta_key', 'PRIMARY', 'meta_key', 'post_id', 'PRIMARY', 'meta_key', 'PRIMARY', 'term_id' ),
			array_column( $rows, 'INDEX_NAME' )
		);
		$this->assertSame(
			array( 'comment_id', 'meta_key', 'meta_id', 'meta_key', 'post_id', 'meta_id', 'meta_key', 'meta_id', 'term_id' ),
			array_column( $rows, 'COLUMN_NAME' )
		);
		$this->assertSame( 2, $statement->columnCount() );
		$this->assertSame( 'INDEX_NAME', $statement->getColumnMeta( 0 )['name'] );
		$this->assertSame( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 9 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME IN ('wptests_postmeta','wptests_termmeta','wptests_commentmeta')
				ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'wptests_commentmeta',
				'wptests_commentmeta',
				'wptests_commentmeta',
				'wptests_postmeta',
				'wptests_postmeta',
				'wptests_postmeta',
				'wptests_termmeta',
				'wptests_termmeta',
				'wptests_termmeta',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );

		$queries = array();
		$rows    = $driver->query(
			"SELECT `INDEX_NAME`, `COLUMN_NAME`, `NON_UNIQUE`, `SEQ_IN_INDEX`
				FROM `information_schema`.`statistics`
				WHERE `TABLE_SCHEMA` = 'wp'
					AND `TABLE_NAME` = 'wptests_postmeta'
				ORDER BY `INDEX_NAME`, `SEQ_IN_INDEX`"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'INDEX_NAME'   => 'meta_key',
					'COLUMN_NAME'  => 'meta_key',
					'NON_UNIQUE'   => 1,
					'SEQ_IN_INDEX' => 1,
				),
				array(
					'INDEX_NAME'   => 'post_id',
					'COLUMN_NAME'  => 'post_id',
					'NON_UNIQUE'   => 1,
					'SEQ_IN_INDEX' => 1,
				),
				array(
					'INDEX_NAME'   => 'PRIMARY',
					'COLUMN_NAME'  => 'meta_id',
					'NON_UNIQUE'   => 0,
					'SEQ_IN_INDEX' => 1,
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'not_wp'
					AND TABLE_NAME = 'wptests_postmeta'
				ORDER BY INDEX_NAME, SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query(
			'CREATE TABLE wptests_stat_shadow (
				id INT,
				persisted VARCHAR(20),
				KEY persistent_key (persisted)
			)'
		);
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_stat_shadow (
				id INT,
				temp_col VARCHAR(20),
				KEY temp_key (temp_col)
			)'
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_stat_shadow'
				ORDER BY INDEX_NAME, SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'INDEX_NAME'  => 'persistent_key',
					'COLUMN_NAME' => 'persisted',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );

		$show_index = $driver->query( 'SHOW INDEX FROM wptests_stat_shadow' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'temp_key' ), array_column( $show_index, 'Key_name' ) );
	}

	public function test_information_schema_statistics_projection_fast_path_rejects_aliases(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_statistics_projection_fixture( $driver );

		$queries = array();
		$rows    = $driver->query(
			"SELECT s.INDEX_NAME, s.COLUMN_NAME
				FROM information_schema.statistics AS s
				WHERE s.TABLE_SCHEMA = 'wp'
					AND s.TABLE_NAME IN ('wptests_postmeta','wptests_termmeta')
				ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( 6, count( $rows ) );
		$this->assertGreaterThan( 0, $this->count_duckdb_information_schema_statistics_stage_queries( $queries ) );
	}

	public function test_information_schema_statistics_projection_fast_path_rejects_unqualified_user_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE statistics (
				INDEX_NAME VARCHAR(20),
				COLUMN_NAME VARCHAR(20),
				TABLE_SCHEMA VARCHAR(20),
				TABLE_NAME VARCHAR(20),
				SEQ_IN_INDEX INT
			)'
		);
		$driver->query(
			"INSERT INTO statistics (INDEX_NAME, COLUMN_NAME, TABLE_SCHEMA, TABLE_NAME, SEQ_IN_INDEX)
				VALUES ('user_idx', 'user_col', 'wp', 'user_stats', 1)"
		);

		$rows = $driver->query(
			"SELECT INDEX_NAME, COLUMN_NAME
				FROM statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME IN ('user_stats')
				ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'INDEX_NAME'  => 'user_idx',
					'COLUMN_NAME' => 'user_col',
				),
			),
			$rows
		);
	}

	public function test_information_schema_statistics_wildcard_and_complex_predicates_fall_back_to_full_refresh(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infostatswildplain (id INT, name VARCHAR(20), KEY name_key (name))' );
		$driver->query( 'CREATE TABLE infostatswildnoise (noise_id INT, marker VARCHAR(20), KEY marker_key (marker))' );
		$driver->query( 'CREATE TABLE infostatswildother (other_id INT, KEY other_key (other_id))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, INDEX_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME LIKE 'infostatswild%'
				ORDER BY TABLE_NAME, INDEX_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'infostatswildnoise',
				'infostatswildother',
				'infostatswildplain',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatswildplain' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatswildnoise' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatswildother' ) );

		$driver->query( 'CREATE TABLE infostatsfuncplain (id INT, name VARCHAR(20), KEY name_key (name))' );
		$driver->query( 'CREATE TABLE infostatsfuncnoise (noise_id INT, marker VARCHAR(20), KEY marker_key (marker))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, INDEX_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND LOWER(TABLE_NAME) = 'infostatsfuncplain'
				ORDER BY TABLE_NAME, INDEX_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'infostatsfuncplain' ), array_column( $rows, 'TABLE_NAME' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsfuncplain' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsfuncnoise' ) );

		$driver->query( 'CREATE TABLE infostatsorplain (id INT, name VARCHAR(20), KEY name_key (name))' );
		$driver->query( 'CREATE TABLE infostatsornoise (noise_id INT, marker VARCHAR(20), KEY marker_key (marker))' );
		$driver->query( 'CREATE TABLE infostatsorother (other_id INT, KEY other_key (other_id))' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, INDEX_NAME
				FROM information_schema.statistics
				WHERE TABLE_SCHEMA = 'wp'
					AND (TABLE_NAME = 'infostatsorplain' OR TABLE_NAME = 'infostatsornoise')
				ORDER BY TABLE_NAME, INDEX_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				'infostatsornoise',
				'infostatsorplain',
			),
			array_column( $rows, 'TABLE_NAME' )
		);
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsorplain' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsornoise' ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'infostatsorother' ) );
	}

	public function test_information_schema_refresh_inserts_are_batched(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE batched_info_metadata (
				id INT NOT NULL,
				slug VARCHAR(20) NOT NULL,
				name VARCHAR(20),
				KEY slug_name_key (slug, name)
			)'
		);

		$column_rows = $driver->query(
			"SELECT column_name
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'batched_info_metadata'
			ORDER BY ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'id', 'slug', 'name' ), array_column( $column_rows, 'COLUMN_NAME' ) );

		$column_inserts = array_values(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'INSERT INTO "__wp_duckdb_information_schema_columns"' );
				}
			)
		);
		$this->assertCount( 1, $column_inserts );
		$this->assertStringContainsString(
			"), ('def', 'wp', 'batched_info_metadata', 'slug'",
			$column_inserts[0]
		);

		$statistics_rows = $driver->query(
			"SELECT index_name, seq_in_index, column_name
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'batched_info_metadata'
			ORDER BY index_name, seq_in_index"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'slug', 'name' ), array_column( $statistics_rows, 'COLUMN_NAME' ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $statistics_rows, 'SEQ_IN_INDEX' ) ) );

		$statistics_inserts = array_values(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'INSERT INTO "__wp_duckdb_information_schema_statistics"' );
				}
			)
		);
		$this->assertCount( 1, $statistics_inserts );
		$this->assertStringContainsString(
			"), ('def', 'wp', 'batched_info_metadata', 1, 'wp', 'slug_name_key', 2, 'name'",
			$statistics_inserts[0]
		);
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

		$referential_constraints_count = $driver->query(
			"SELECT COUNT(*) AS count
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'"
		)->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $referential_constraints_count['count'] );

		$check_constraints_count = $driver->query(
			"SELECT COUNT(*) AS count
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'"
		)->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $check_constraints_count['count'] );

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

	public function test_information_schema_constraint_projection_fast_path_skips_stage_tables(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_constraint_projection_fixture( $driver );

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_constraints_target'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'PRIMARY',
					'CONSTRAINT_TYPE' => 'PRIMARY KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'unique_site_option',
					'CONSTRAINT_TYPE' => 'UNIQUE',
					'ENFORCED'        => 'YES',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_table_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME
				FROM information_schema.key_column_usage
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_constraints_target'
				ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'       => 'PRIMARY',
					'COLUMN_NAME'           => 'site_id',
					'ORDINAL_POSITION'      => 1,
					'REFERENCED_TABLE_NAME' => null,
				),
				array(
					'CONSTRAINT_NAME'       => 'PRIMARY',
					'COLUMN_NAME'           => 'option_id',
					'ORDINAL_POSITION'      => 2,
					'REFERENCED_TABLE_NAME' => null,
				),
				array(
					'CONSTRAINT_NAME'       => 'unique_site_option',
					'COLUMN_NAME'           => 'site_id',
					'ORDINAL_POSITION'      => 1,
					'REFERENCED_TABLE_NAME' => null,
				),
				array(
					'CONSTRAINT_NAME'       => 'unique_site_option',
					'COLUMN_NAME'           => 'option_name',
					'ORDINAL_POSITION'      => 2,
					'REFERENCED_TABLE_NAME' => null,
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_key_column_usage_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 4 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME
				FROM information_schema.table_constraints
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_constraints_missing'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_table_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
				FROM information_schema.key_column_usage
				WHERE TABLE_SCHEMA = 'not_wp'
					AND TABLE_NAME = 'wptests_constraints_target'
				ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_key_column_usage_stage_queries( $queries ) );

		$driver->query(
			'CREATE TABLE wptests_constraint_shadow (
				id INT,
				persisted VARCHAR(20),
				UNIQUE KEY persistent_key (persisted)
			)'
		);
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_constraint_shadow (
				id INT,
				temp_col VARCHAR(20),
				UNIQUE KEY temp_key (temp_col)
			)'
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_constraint_shadow'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'persistent_key',
					'CONSTRAINT_TYPE' => 'UNIQUE',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_table_constraints_stage_queries( $queries ) );
	}

	public function test_information_schema_constraint_projection_fast_path_rejects_aliases(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_constraint_projection_fixture( $driver );

		$queries = array();
		$rows    = $driver->query(
			"SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE
				FROM information_schema.table_constraints AS tc
				WHERE tc.TABLE_SCHEMA = 'wp'
					AND tc.TABLE_NAME = 'wptests_constraints_target'
				ORDER BY tc.CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'PRIMARY', 'unique_site_option' ), array_column( $rows, 'CONSTRAINT_NAME' ) );
		$this->assertGreaterThan( 0, $this->count_duckdb_information_schema_table_constraints_stage_queries( $queries ) );
	}

	public function test_information_schema_check_constraint_projection_fast_path_skips_stage_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$driver->query(
			'CREATE TABLE checks (
				id INT,
				amount INT,
				CONSTRAINT amount_positive CHECK (amount > 0),
				CHECK (id IS NULL OR id >= 0)
			)'
		);
		$driver->query(
			'CREATE TABLE checks_noise (
				id INT,
				qty INT,
				CONSTRAINT qty_positive CHECK (qty > 0)
			)'
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CHECK_CLAUSE'    => 'amount > 0',
				),
				array(
					'CONSTRAINT_NAME' => 'checks_chk_1',
					'CHECK_CLAUSE'    => 'id IS NULL OR id >= 0',
				),
				array(
					'CONSTRAINT_NAME' => 'qty_positive',
					'CHECK_CLAUSE'    => 'qty > 0',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_check_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'amount_positive',
					'CHECK_CLAUSE'       => 'amount > 0',
				),
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'checks_chk_1',
					'CHECK_CLAUSE'       => 'id IS NULL OR id >= 0',
				),
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'qty_positive',
					'CHECK_CLAUSE'       => 'qty > 0',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_check_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 3 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND CONSTRAINT_NAME = 'amount_positive'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CHECK_CLAUSE'    => 'amount > 0',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_check_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME
				FROM information_schema.check_constraints
				WHERE CONSTRAINT_SCHEMA = 'not_wp'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_check_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_information_schema_referential_constraint_projection_fast_path_skips_stage_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE child_named (
				id INT,
				parent_id INT,
				CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE RESTRICT ON UPDATE NO ACTION
			)'
		);
		$driver->query(
			'CREATE TABLE child_generated (
				id INT,
				parent_id INT,
				FOREIGN KEY (parent_id) REFERENCES parents (id)
			)'
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION,
					UPDATE_RULE, DELETE_RULE, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND TABLE_NAME IN ('child_generated', 'child_named')
				ORDER BY TABLE_NAME, CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'child_generated_ibfk_1',
					'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY',
					'MATCH_OPTION'           => 'NONE',
					'UPDATE_RULE'            => 'NO ACTION',
					'DELETE_RULE'            => 'NO ACTION',
					'TABLE_NAME'             => 'child_generated',
					'REFERENCED_TABLE_NAME'  => 'parents',
				),
				array(
					'CONSTRAINT_NAME'        => 'fk_parent',
					'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY',
					'MATCH_OPTION'           => 'NONE',
					'UPDATE_RULE'            => 'NO ACTION',
					'DELETE_RULE'            => 'RESTRICT',
					'TABLE_NAME'             => 'child_named',
					'REFERENCED_TABLE_NAME'  => 'parents',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME,
					UNIQUE_CONSTRAINT_CATALOG, UNIQUE_CONSTRAINT_SCHEMA,
					UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE,
					TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND TABLE_NAME IN ('child_generated', 'child_named')
				ORDER BY TABLE_NAME, CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_CATALOG'        => 'def',
					'CONSTRAINT_SCHEMA'         => 'wp',
					'CONSTRAINT_NAME'           => 'child_generated_ibfk_1',
					'UNIQUE_CONSTRAINT_CATALOG' => 'def',
					'UNIQUE_CONSTRAINT_SCHEMA'  => 'wp',
					'UNIQUE_CONSTRAINT_NAME'    => 'PRIMARY',
					'MATCH_OPTION'              => 'NONE',
					'UPDATE_RULE'               => 'NO ACTION',
					'DELETE_RULE'               => 'NO ACTION',
					'TABLE_NAME'                => 'child_generated',
					'REFERENCED_TABLE_NAME'     => 'parents',
				),
				array(
					'CONSTRAINT_CATALOG'        => 'def',
					'CONSTRAINT_SCHEMA'         => 'wp',
					'CONSTRAINT_NAME'           => 'fk_parent',
					'UNIQUE_CONSTRAINT_CATALOG' => 'def',
					'UNIQUE_CONSTRAINT_SCHEMA'  => 'wp',
					'UNIQUE_CONSTRAINT_NAME'    => 'PRIMARY',
					'MATCH_OPTION'              => 'NONE',
					'UPDATE_RULE'               => 'NO ACTION',
					'DELETE_RULE'               => 'RESTRICT',
					'TABLE_NAME'                => 'child_named',
					'REFERENCED_TABLE_NAME'     => 'parents',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
				FROM information_schema.referential_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND TABLE_NAME = 'child_named'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'       => 'fk_parent',
					'TABLE_NAME'            => 'child_named',
					'REFERENCED_TABLE_NAME' => 'parents',
					'UPDATE_RULE'           => 'NO ACTION',
					'DELETE_RULE'           => 'RESTRICT',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND TABLE_NAME = 'child_named'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'fk_parent',
					'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY',
					'TABLE_NAME'             => 'child_named',
					'REFERENCED_TABLE_NAME'  => 'parents',
				),
			),
			$rows
		);
		$this->assertSame( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE CONSTRAINT_SCHEMA = 'wp'
					AND TABLE_NAME = 'missing_child'
				ORDER BY CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $rows );
		$this->assertSame( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_information_schema_check_and_referential_constraint_projection_fast_path_rejects_aliases(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$driver->query(
			'CREATE TABLE checks (
				id INT,
				CONSTRAINT id_positive CHECK (id > 0)
			)'
		);
		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE child_named (
				id INT,
				parent_id INT,
				CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES parents (id)
			)'
		);

		$queries = array();
		$rows    = $driver->query(
			"SELECT cc.CONSTRAINT_NAME
				FROM information_schema.check_constraints AS cc
				WHERE cc.CONSTRAINT_SCHEMA = 'wp'
				ORDER BY cc.CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'id_positive' ), array_column( $rows, 'CONSTRAINT_NAME' ) );
		$this->assertGreaterThan( 0, $this->count_duckdb_information_schema_check_constraints_stage_queries( $queries ) );

		$queries = array();
		$rows    = $driver->query(
			"SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME
				FROM information_schema.referential_constraints AS rc
				WHERE rc.CONSTRAINT_SCHEMA = 'wp'
					AND rc.TABLE_NAME = 'child_named'
				ORDER BY rc.CONSTRAINT_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'fk_parent' ), array_column( $rows, 'CONSTRAINT_NAME' ) );
		$this->assertGreaterThan( 0, $this->count_duckdb_information_schema_referential_constraints_stage_queries( $queries ) );
	}

	public function test_create_table_check_constraints_use_native_enforcement_and_mysql_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TABLE checks (
				id INT,
				amount INT,
				CONSTRAINT amount_positive CHECK (amount > 0),
				CHECK (id IS NULL OR id >= 0)
			)'
		);

		$this->assertSame( 2, $driver->query( 'INSERT INTO checks (id, amount) VALUES (1, 10), (NULL, 2)' )->rowCount() );

		try {
			$driver->query( 'INSERT INTO checks (id, amount) VALUES (2, -1)' );
			$this->fail( 'Expected CHECK constraint enforcement to reject a negative amount.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( 'INSERT INTO checks (id, amount) VALUES (-1, 1)' );
			$this->fail( 'Expected CHECK constraint enforcement to reject a negative id.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'checks'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'checks_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
			),
			$constraints
		);

		$check_constraints = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'amount_positive',
					'CHECK_CLAUSE'       => 'amount > 0',
				),
				array(
					'CONSTRAINT_CATALOG' => 'def',
					'CONSTRAINT_SCHEMA'  => 'wp',
					'CONSTRAINT_NAME'    => 'checks_chk_1',
					'CHECK_CLAUSE'       => 'id IS NULL OR id >= 0',
				),
			),
			$check_constraints
		);

		$internal_checks = $driver->query(
			"SELECT cc.CONSTRAINT_NAME
			FROM information_schema.check_constraints AS cc
			JOIN information_schema.table_constraints AS tc
				ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
				AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
			WHERE tc.TABLE_NAME LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal_checks );

		$create = $driver->query( 'SHOW CREATE TABLE checks' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `checks` (',
					'  `id` int DEFAULT NULL,',
					'  `amount` int DEFAULT NULL,',
					'  CONSTRAINT `amount_positive` CHECK (amount > 0),',
					'  CONSTRAINT `checks_chk_1` CHECK (id IS NULL OR id >= 0)',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create['Create Table']
		);
	}

	public function test_create_table_inline_check_constraints_use_native_enforcement_and_mysql_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TABLE inline_checks (
				id INT CHECK (id >= 0),
				amount INT CHECK (amount > 0) ENFORCED
			)'
		);

		$this->assertSame( 2, $driver->query( 'INSERT INTO inline_checks (id, amount) VALUES (1, 10), (NULL, 2)' )->rowCount() );

		try {
			$driver->query( 'INSERT INTO inline_checks (id, amount) VALUES (-1, 1)' );
			$this->fail( 'Expected inline CHECK constraint enforcement to reject a negative id.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( 'INSERT INTO inline_checks (id, amount) VALUES (2, -1)' );
			$this->fail( 'Expected inline CHECK constraint enforcement to reject a negative amount.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( 'UPDATE inline_checks SET amount = 0 WHERE id = 1' );
			$this->fail( 'Expected inline CHECK constraint enforcement to reject an invalid UPDATE.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( 'UPDATE inline_checks SET id = -2 WHERE amount = 2' );
			$this->fail( 'Expected inline CHECK constraint enforcement to reject an invalid UPDATE.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'amount' => 10,
				),
				array(
					'id'     => null,
					'amount' => 2,
				),
			),
			$driver->query( 'SELECT id, amount FROM inline_checks ORDER BY amount DESC' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'inline_checks'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'inline_checks_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'inline_checks_chk_2',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
			),
			$constraints
		);

		$check_constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'inline_checks_chk_1',
					'CHECK_CLAUSE'    => 'id >= 0',
				),
				array(
					'CONSTRAINT_NAME' => 'inline_checks_chk_2',
					'CHECK_CLAUSE'    => 'amount > 0',
				),
			),
			$check_constraints
		);

		$create = $driver->query( 'SHOW CREATE TABLE inline_checks' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `inline_checks` (',
					'  `id` int DEFAULT NULL,',
					'  `amount` int DEFAULT NULL,',
					'  CONSTRAINT `inline_checks_chk_1` CHECK (id >= 0),',
					'  CONSTRAINT `inline_checks_chk_2` CHECK (amount > 0)',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create['Create Table']
		);
	}

	public function test_create_table_check_not_enforced_uses_metadata_without_native_enforcement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TABLE checks_not_enforced (
				id INT CHECK (id > 0) NOT ENFORCED,
				amount INT,
				CONSTRAINT amount_limit CHECK (amount < 5) NOT ENFORCED,
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);

		$native_create_queries = array_values(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'CREATE TABLE "checks_not_enforced" ' );
				}
			)
		);
		$this->assertCount( 1, $native_create_queries );
		$this->assertStringNotContainsString( 'amount_limit', $native_create_queries[0] );
		$this->assertStringNotContainsString( 'checks_not_enforced_chk_1', $native_create_queries[0] );
		$this->assertStringContainsString( 'amount_positive', $native_create_queries[0] );

		$this->assertSame( 1, $driver->query( 'INSERT INTO checks_not_enforced (id, amount) VALUES (0, 10)' )->rowCount() );
		try {
			$driver->query( 'INSERT INTO checks_not_enforced (id, amount) VALUES (1, -1)' );
			$this->fail( 'Expected enforced CHECK constraint to reject a negative amount.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_limit',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'checks_not_enforced_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'checks_not_enforced'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_limit',
					'CHECK_CLAUSE'    => 'amount < 5',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CHECK_CLAUSE'    => 'amount > 0',
				),
				array(
					'CONSTRAINT_NAME' => 'checks_not_enforced_chk_1',
					'CHECK_CLAUSE'    => 'id > 0',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create = $driver->query( 'SHOW CREATE TABLE checks_not_enforced' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `checks_not_enforced` (',
					'  `id` int DEFAULT NULL,',
					'  `amount` int DEFAULT NULL,',
					'  CONSTRAINT `amount_limit` CHECK (amount < 5) /*!80016 NOT ENFORCED */,',
					'  CONSTRAINT `amount_positive` CHECK (amount > 0),',
					'  CONSTRAINT `checks_not_enforced_chk_1` CHECK (id > 0) /*!80016 NOT ENFORCED */',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create['Create Table']
		);

		$this->assertSame( 0, $driver->query( 'ALTER TABLE checks_not_enforced DROP CHECK amount_positive' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_limit',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
				array(
					'CONSTRAINT_NAME' => 'checks_not_enforced_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'checks_not_enforced'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$create = $driver->query( 'SHOW CREATE TABLE checks_not_enforced' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'amount_positive', $create['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `amount_limit` CHECK (amount < 5) /*!80016 NOT ENFORCED */', $create['Create Table'] );
		$this->assertSame( 1, $driver->query( 'INSERT INTO checks_not_enforced (id, amount) VALUES (0, -1)' )->rowCount() );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE checks_not_enforced DROP CHECK amount_limit' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE checks_not_enforced DROP CONSTRAINT checks_not_enforced_chk_1' )->rowCount() );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'checks_not_enforced'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$create = $driver->query( 'SHOW CREATE TABLE checks_not_enforced' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'NOT ENFORCED', $create['Create Table'] );
		$this->assertStringNotContainsString( 'CONSTRAINT', $create['Create Table'] );
		$this->assertSame( 1, $driver->query( 'INSERT INTO checks_not_enforced (id, amount) VALUES (0, -1)' )->rowCount() );
	}

	public function test_create_table_foreign_keys_use_native_enforcement_and_mysql_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE child_named (
				id INT,
				parent_id INT,
				CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE RESTRICT ON UPDATE NO ACTION
			)'
		);
		$driver->query(
			'CREATE TABLE child_generated (
				id INT,
				parent_id INT,
				FOREIGN KEY (parent_id) REFERENCES parents (id)
			)'
		);

		$create_queries = $driver->get_last_duckdb_queries();
		$this->assertContains(
			'CREATE TABLE "child_generated" ("id" INTEGER, "parent_id" INTEGER, CONSTRAINT "child_generated_ibfk_1" FOREIGN KEY ("parent_id") REFERENCES "parents" ("id"))',
			$create_queries
		);

		$driver->query( 'INSERT INTO parents (id) VALUES (1)' );
		$this->assertSame( 1, $driver->query( 'INSERT INTO child_named (id, parent_id) VALUES (10, 1)' )->rowCount() );

		try {
			$driver->query( 'INSERT INTO child_named (id, parent_id) VALUES (11, 404)' );
			$this->fail( 'Expected FOREIGN KEY enforcement to reject a missing parent row.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		try {
			$driver->query( 'DELETE FROM parents WHERE id = 1' );
			$this->fail( 'Expected FOREIGN KEY enforcement to restrict deleting a referenced parent row.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name IN ('child_generated', 'child_named')
			ORDER BY table_name, constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'child_generated_ibfk_1',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'fk_parent',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
			),
			$constraints
		);

		$referential_constraints = $driver->query(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME,
				UNIQUE_CONSTRAINT_CATALOG, UNIQUE_CONSTRAINT_SCHEMA,
				UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE,
				TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('child_generated', 'child_named')
			ORDER BY table_name, constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_CATALOG'        => 'def',
					'CONSTRAINT_SCHEMA'         => 'wp',
					'CONSTRAINT_NAME'           => 'child_generated_ibfk_1',
					'UNIQUE_CONSTRAINT_CATALOG' => 'def',
					'UNIQUE_CONSTRAINT_SCHEMA'  => 'wp',
					'UNIQUE_CONSTRAINT_NAME'    => 'PRIMARY',
					'MATCH_OPTION'              => 'NONE',
					'UPDATE_RULE'               => 'NO ACTION',
					'DELETE_RULE'               => 'NO ACTION',
					'TABLE_NAME'                => 'child_generated',
					'REFERENCED_TABLE_NAME'     => 'parents',
				),
				array(
					'CONSTRAINT_CATALOG'        => 'def',
					'CONSTRAINT_SCHEMA'         => 'wp',
					'CONSTRAINT_NAME'           => 'fk_parent',
					'UNIQUE_CONSTRAINT_CATALOG' => 'def',
					'UNIQUE_CONSTRAINT_SCHEMA'  => 'wp',
					'UNIQUE_CONSTRAINT_NAME'    => 'PRIMARY',
					'MATCH_OPTION'              => 'NONE',
					'UPDATE_RULE'               => 'NO ACTION',
					'DELETE_RULE'               => 'RESTRICT',
					'TABLE_NAME'                => 'child_named',
					'REFERENCED_TABLE_NAME'     => 'parents',
				),
			),
			$referential_constraints
		);

		$usage = $driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('child_generated', 'child_named')
			ORDER BY table_name, constraint_name, ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'               => 'child_generated_ibfk_1',
					'TABLE_NAME'                    => 'child_generated',
					'COLUMN_NAME'                   => 'parent_id',
					'ORDINAL_POSITION'              => 1,
					'POSITION_IN_UNIQUE_CONSTRAINT' => 1,
					'REFERENCED_TABLE_SCHEMA'       => 'wp',
					'REFERENCED_TABLE_NAME'         => 'parents',
					'REFERENCED_COLUMN_NAME'        => 'id',
				),
				array(
					'CONSTRAINT_NAME'               => 'fk_parent',
					'TABLE_NAME'                    => 'child_named',
					'COLUMN_NAME'                   => 'parent_id',
					'ORDINAL_POSITION'              => 1,
					'POSITION_IN_UNIQUE_CONSTRAINT' => 1,
					'REFERENCED_TABLE_SCHEMA'       => 'wp',
					'REFERENCED_TABLE_NAME'         => 'parents',
					'REFERENCED_COLUMN_NAME'        => 'id',
				),
			),
			$usage
		);

		$internal_references = $driver->query(
			"SELECT table_name
			FROM information_schema.referential_constraints
			WHERE table_name LIKE '__wp_duckdb_%'
				OR referenced_table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal_references );

		$create = $driver->query( 'SHOW CREATE TABLE child_named' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `child_named` (',
					'  `id` int DEFAULT NULL,',
					'  `parent_id` int DEFAULT NULL,',
					'  CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE RESTRICT',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create['Create Table']
		);
	}

	public function test_create_table_inline_foreign_keys_use_native_enforcement_and_mysql_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE child_inline (
				id INT,
				parent_id INT REFERENCES parents (id) ON DELETE RESTRICT ON UPDATE NO ACTION
			)'
		);
		$driver->query(
			'CREATE TABLE child_inline_default (
				id INT,
				parent_id INT REFERENCES parents (id)
			)'
		);

		$create_queries = $driver->get_last_duckdb_queries();
		$this->assertContains(
			'CREATE TABLE "child_inline_default" ("id" INTEGER, "parent_id" INTEGER, CONSTRAINT "child_inline_default_ibfk_1" FOREIGN KEY ("parent_id") REFERENCES "parents" ("id"))',
			$create_queries
		);

		$driver->query( 'INSERT INTO parents (id) VALUES (1)' );
		$this->assertSame( 1, $driver->query( 'INSERT INTO child_inline (id, parent_id) VALUES (10, 1)' )->rowCount() );
		$this->assertSame( 1, $driver->query( 'INSERT INTO child_inline_default (id, parent_id) VALUES (20, 1)' )->rowCount() );

		try {
			$driver->query( 'INSERT INTO child_inline (id, parent_id) VALUES (11, 404)' );
			$this->fail( 'Expected inline FOREIGN KEY enforcement to reject a missing parent row.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		try {
			$driver->query( 'UPDATE child_inline SET parent_id = 404 WHERE id = 10' );
			$this->fail( 'Expected inline FOREIGN KEY enforcement to reject updating a child to a missing parent row.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		try {
			$driver->query( 'UPDATE parents SET id = 2 WHERE id = 1' );
			$this->fail( 'Expected inline FOREIGN KEY enforcement to restrict updating a referenced parent key.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		$this->assertSame(
			array(
				array(
					'id'        => 10,
					'parent_id' => 1,
				),
			),
			$driver->query( 'SELECT id, parent_id FROM child_inline' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'id' => 1 ) ),
			$driver->query( 'SELECT id FROM parents' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'DELETE FROM parents WHERE id = 1' );
			$this->fail( 'Expected inline FOREIGN KEY enforcement to restrict deleting a referenced parent row.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'constraint', strtolower( $e->getMessage() ) );
		}

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name IN ('child_inline', 'child_inline_default')
			ORDER BY table_name, constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'child_inline_ibfk_1',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'child_inline_default_ibfk_1',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
			),
			$constraints
		);

		$referential_constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION,
				UPDATE_RULE, DELETE_RULE, TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('child_inline', 'child_inline_default')
			ORDER BY table_name, constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'child_inline_ibfk_1',
					'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY',
					'MATCH_OPTION'           => 'NONE',
					'UPDATE_RULE'            => 'NO ACTION',
					'DELETE_RULE'            => 'RESTRICT',
					'TABLE_NAME'             => 'child_inline',
					'REFERENCED_TABLE_NAME'  => 'parents',
				),
				array(
					'CONSTRAINT_NAME'        => 'child_inline_default_ibfk_1',
					'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY',
					'MATCH_OPTION'           => 'NONE',
					'UPDATE_RULE'            => 'NO ACTION',
					'DELETE_RULE'            => 'NO ACTION',
					'TABLE_NAME'             => 'child_inline_default',
					'REFERENCED_TABLE_NAME'  => 'parents',
				),
			),
			$referential_constraints
		);

		$usage = $driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('child_inline', 'child_inline_default')
			ORDER BY table_name, constraint_name, ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'               => 'child_inline_ibfk_1',
					'TABLE_NAME'                    => 'child_inline',
					'COLUMN_NAME'                   => 'parent_id',
					'ORDINAL_POSITION'              => 1,
					'POSITION_IN_UNIQUE_CONSTRAINT' => 1,
					'REFERENCED_TABLE_SCHEMA'       => 'wp',
					'REFERENCED_TABLE_NAME'         => 'parents',
					'REFERENCED_COLUMN_NAME'        => 'id',
				),
				array(
					'CONSTRAINT_NAME'               => 'child_inline_default_ibfk_1',
					'TABLE_NAME'                    => 'child_inline_default',
					'COLUMN_NAME'                   => 'parent_id',
					'ORDINAL_POSITION'              => 1,
					'POSITION_IN_UNIQUE_CONSTRAINT' => 1,
					'REFERENCED_TABLE_SCHEMA'       => 'wp',
					'REFERENCED_TABLE_NAME'         => 'parents',
					'REFERENCED_COLUMN_NAME'        => 'id',
				),
			),
			$usage
		);

		$create = $driver->query( 'SHOW CREATE TABLE child_inline' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `child_inline` (',
					'  `id` int DEFAULT NULL,',
					'  `parent_id` int DEFAULT NULL,',
					'  CONSTRAINT `child_inline_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE RESTRICT',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create['Create Table']
		);
	}

	public function test_foreign_keys_referencing_driver_managed_unique_keys_expose_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TABLE unique_parent (
				id INT PRIMARY KEY,
				code INT,
				UNIQUE KEY code_u (code)
			)'
		);

		$parent_constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'unique_parent'
			ORDER BY constraint_type, constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'PRIMARY',
					'CONSTRAINT_TYPE' => 'PRIMARY KEY',
				),
				array(
					'CONSTRAINT_NAME' => 'code_u',
					'CONSTRAINT_TYPE' => 'UNIQUE',
				),
			),
			$parent_constraints
		);

		$parent_usage = $driver->query(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_NAME,
				REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'unique_parent'
			ORDER BY constraint_name, ordinal_position"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'               => 'code_u',
					'TABLE_NAME'                    => 'unique_parent',
					'COLUMN_NAME'                   => 'code',
					'POSITION_IN_UNIQUE_CONSTRAINT' => null,
					'REFERENCED_TABLE_NAME'         => null,
					'REFERENCED_COLUMN_NAME'        => null,
				),
				array(
					'CONSTRAINT_NAME'               => 'PRIMARY',
					'TABLE_NAME'                    => 'unique_parent',
					'COLUMN_NAME'                   => 'id',
					'POSITION_IN_UNIQUE_CONSTRAINT' => null,
					'REFERENCED_TABLE_NAME'         => null,
					'REFERENCED_COLUMN_NAME'        => null,
				),
			),
			$parent_usage
		);

		$driver->query(
			'CREATE TABLE unique_child (
				id INT,
				parent_code INT,
				CONSTRAINT fk_parent_code FOREIGN KEY (parent_code) REFERENCES unique_parent (code)
			)'
		);

		$referential_constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp' AND table_name = 'unique_child'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'fk_parent_code',
					'UNIQUE_CONSTRAINT_NAME' => 'code_u',
					'TABLE_NAME'             => 'unique_child',
					'REFERENCED_TABLE_NAME'  => 'unique_parent',
				),
			),
			$referential_constraints
		);

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'               => 'fk_parent_code',
					'TABLE_NAME'                    => 'unique_child',
					'COLUMN_NAME'                   => 'parent_code',
					'POSITION_IN_UNIQUE_CONSTRAINT' => 1,
					'REFERENCED_TABLE_NAME'         => 'unique_parent',
					'REFERENCED_COLUMN_NAME'        => 'code',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
					POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_NAME,
					REFERENCED_COLUMN_NAME
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp' AND table_name = 'unique_child'
				ORDER BY constraint_name, ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create = $driver->query( 'SHOW CREATE TABLE unique_child' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringContainsString(
			'CONSTRAINT `fk_parent_code` FOREIGN KEY (`parent_code`) REFERENCES `unique_parent` (`code`)',
			$create['Create Table']
		);
	}

	public function test_information_schema_tables_recovers_aborted_transaction_before_refresh(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_develop_tests',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);

		$connection = $driver->get_connection();
		$connection->beginTransaction();
		try {
			$connection->query( "SELECT CAST('not-an-integer' AS INTEGER)" );
			$this->fail( 'Expected raw DuckDB query failure to abort the active transaction.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DuckDB query failed:', $e->getMessage() );
		}
		$this->assertTrue( $connection->inTransaction() );

		$rows = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
				SUM(data_length + index_length) as 'bytes'
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
				AND TABLE_NAME IN ('wptests_options')
			GROUP BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertFalse( $connection->inTransaction() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wptests_options', $rows[0]['table'] );
		$this->assertSame( 0, (int) $rows[0]['rows'] );
		$this->assertSame( 0, (int) $rows[0]['bytes'] );
		$this->assertContains(
			'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_transaction_recovery_probe" AS SELECT 1 AS ok',
			$driver->get_last_duckdb_queries()
		);
		$this->assertContains( 'ROLLBACK', $driver->get_last_duckdb_queries() );
	}

	public function test_information_schema_tables_recovers_untracked_aborted_native_transaction_before_refresh(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_develop_tests',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);

		$connection = $driver->get_connection();
		$connection->query( 'BEGIN TRANSACTION' );
		$this->assertFalse( $connection->inTransaction() );
		try {
			$connection->query( "SELECT CAST('not-an-integer' AS INTEGER)" );
			$this->fail( 'Expected raw DuckDB query failure to abort the untracked transaction.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DuckDB query failed:', $e->getMessage() );
		}
		$this->assertFalse( $connection->inTransaction() );

		$rows = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
				SUM(data_length + index_length) as 'bytes'
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
				AND TABLE_NAME IN ('wptests_options')
			GROUP BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertFalse( $connection->inTransaction() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wptests_options', $rows[0]['table'] );
		$this->assertSame( 0, (int) $rows[0]['rows'] );
		$this->assertSame( 0, (int) $rows[0]['bytes'] );
		$this->assertContains(
			'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_transaction_recovery_probe" AS SELECT 1 AS ok',
			$driver->get_last_duckdb_queries()
		);
		$this->assertContains( 'ROLLBACK', $driver->get_last_duckdb_queries() );
	}

	public function test_information_schema_tables_retries_refresh_after_aborted_transaction_probe_miss(): void {
		$this->requireDuckDBRuntime();

		$inner_connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection       = new class( $inner_connection ) extends WP_DuckDB_Connection {
			public $queries = array();
			private $inner_connection;
			private $fail_information_schema_tables_refresh = true;

			public function __construct( WP_DuckDB_Connection $inner_connection ) {
				$this->inner_connection = $inner_connection;
			}

			public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
				$this->queries[] = $sql;
				if (
					$this->fail_information_schema_tables_refresh
					&& 0 === strpos( $sql, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_tables"' )
				) {
					$this->fail_information_schema_tables_refresh = false;
					throw new WP_DuckDB_Driver_Exception( 'DuckDB query failed: TransactionContext Error: Current transaction is aborted (please ROLLBACK)' );
				}

				return $this->inner_connection->query( $sql, $params );
			}

			public function rollbackNativeTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				$this->queries[] = 'ROLLBACK';
				try {
					$this->inner_connection->query( 'ROLLBACK' );
				} catch ( WP_DuckDB_Driver_Exception $e ) {
				}
				return true;
			}
		};
		$driver           = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wordpress_develop_tests',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);

		$rows = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
				SUM(data_length + index_length) as 'bytes'
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
				AND TABLE_NAME IN ('wptests_options')
			GROUP BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$refresh_attempts = array_values(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_tables"' );
				}
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'wptests_options', $rows[0]['table'] );
		$this->assertCount( 2, $refresh_attempts );
		$this->assertContains( 'ROLLBACK', $driver->get_last_duckdb_queries() );
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
		$driver->query(
			"INSERT INTO plain (id, name)
			VALUES (1, 'one'), (2, 'two'), (3, 'three')"
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
		$this->assertSame( 2, $rows[0]['TABLE_ROWS'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $rows[0]['TABLE_COLLATION'] );
		$this->assertSame( 'Options table', $rows[0]['TABLE_COMMENT'] );
		$this->assertSame( 3, $rows[0]['AUTO_INCREMENT'] );
		$this->assertRegExp( '/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[0]['CREATE_TIME'] );
		$this->assertSame( 'InnoDB', $rows[1]['ENGINE'] );
		$this->assertSame( 'Dynamic', $rows[1]['ROW_FORMAT'] );
		$this->assertSame( 3, $rows[1]['TABLE_ROWS'] );
		$this->assertSame( null, $rows[1]['AUTO_INCREMENT'] );

		$aliased = $driver->query(
			"SELECT t.TABLE_NAME, t.ENGINE, t.TABLE_ROWS, t.`AUTO_INCREMENT`
			FROM information_schema.tables t
			WHERE t.TABLE_SCHEMA = 'wp'
			ORDER BY t.TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'metadata', 'plain' ), array_column( $aliased, 'TABLE_NAME' ) );
		$this->assertSame( array( 2, 3 ), array_column( $aliased, 'TABLE_ROWS' ) );
		$this->assertSame( 3, $aliased[0]['AUTO_INCREMENT'] );

		$internal = $driver->query(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_name LIKE '__wp_duckdb_%'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array(), $internal );
	}

	public function test_information_schema_tables_reports_current_row_counts_for_site_health(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wordpress_develop_tests',
			)
		);
		$driver->query(
			"CREATE TABLE wptests_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				)"
		);
		$driver->query(
			"CREATE TABLE wptests_terms (
					term_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(200) NOT NULL DEFAULT ''
				)"
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test'), ('blogname', 'Test')"
		);
		$driver->query( "INSERT INTO wptests_terms (name) VALUES ('one'), ('two')" );

		$rows = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
					SUM(data_length + index_length) as 'bytes'
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
					AND TABLE_NAME IN ('wptests_options','wptests_terms')
				GROUP BY TABLE_NAME
				ORDER BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'table' => 'wptests_options',
					'rows'  => 3,
					'bytes' => 0,
				),
				array(
					'table' => 'wptests_terms',
					'rows'  => 2,
					'bytes' => 0,
				),
			),
			$rows
		);

		$driver->query( "INSERT INTO wptests_terms (name) VALUES ('three')" );
		$this->assertSame(
			array( array( 'TABLE_ROWS' => 3 ) ),
			$driver->query(
				"SELECT TABLE_ROWS
					FROM information_schema.tables
					WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
						AND TABLE_NAME = 'wptests_terms'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'CREATE TABLE row_shadow (id INT)' );
		$driver->query( 'INSERT INTO row_shadow VALUES (1)' );
		$driver->query( 'CREATE TEMPORARY TABLE row_shadow (id INT)' );
		$driver->query( 'INSERT INTO row_shadow VALUES (1), (2)' );
		$this->assertSame(
			array( array( 'TABLE_ROWS' => 1 ) ),
			$driver->query(
				"SELECT TABLE_ROWS
					FROM information_schema.tables
					WHERE TABLE_SCHEMA = 'wordpress_develop_tests'
						AND TABLE_NAME = 'row_shadow'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$status = $driver->query( "SHOW TABLE STATUS LIKE 'row_shadow'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertCount( 1, $status );
		$this->assertSame( 1, $status[0]['Rows'] );
		$this->assertSame(
			array( array( 'count' => 2 ) ),
			$driver->query( 'SELECT COUNT(*) AS count FROM row_shadow' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_information_schema_tables_exact_name_materializes_only_matching_table(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infotablesexactplain (id INT, name TEXT)' );
		$driver->query( 'CREATE TABLE infotablesexactnoise (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT)' );
		$driver->query( 'CREATE TABLE infotablesexactother (id INT)' );
		$driver->query( "INSERT INTO infotablesexactplain VALUES (1, 'one'), (2, 'two')" );
		$driver->query( "INSERT INTO infotablesexactnoise (name) VALUES ('noise')" );
		$driver->query( 'INSERT INTO infotablesexactother VALUES (1)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, TABLE_ROWS
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infotablesexactplain'"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'TABLE_NAME' => 'infotablesexactplain',
					'TABLE_ROWS' => 2,
				),
			),
			$rows
		);
		$this->assertSame( 1, $this->count_duckdb_table_row_count_queries( $queries ) );

		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotablesexactplain"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesexactnoise"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesexactother"', $count_sql );
		$this->assertSame( 0, $this->count_duckdb_currval_queries( $queries ) );

		$driver->query( "INSERT INTO infotablesexactplain VALUES (3, 'three')" );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, TABLE_ROWS
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infotablesexactplain'"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( 3, $rows[0]['TABLE_ROWS'] );
		$this->assertSame( 1, $this->count_duckdb_table_row_count_queries( $queries ) );

		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotablesexactplain"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesexactnoise"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesexactother"', $count_sql );

		$queries = array();
		$missing = $driver->query(
			"SELECT TABLE_NAME, TABLE_ROWS
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'infotablesexactmissing'"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array(), $missing );
		$this->assertSame( 0, $this->count_duckdb_table_row_count_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_currval_queries( $queries ) );
	}

	public function test_information_schema_tables_in_list_materializes_only_requested_tables_for_site_health(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE infotablesin_options (
				option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				option_value LONGTEXT NOT NULL
			)"
		);
		$driver->query(
			"CREATE TABLE infotablesin_terms (
				term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(200) NOT NULL DEFAULT ''
			)"
		);
		$driver->query( 'CREATE TABLE infotablesin_noise (id INT)' );
		$driver->query(
			"INSERT INTO infotablesin_options (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test'), ('blogname', 'Test')"
		);
		$driver->query( "INSERT INTO infotablesin_terms (name) VALUES ('one'), ('two')" );
		$driver->query( 'INSERT INTO infotablesin_noise VALUES (1)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
					SUM(data_length + index_length) AS 'bytes'
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME IN ('infotablesin_options','infotablesin_terms')
				GROUP BY TABLE_NAME
				ORDER BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array(
				array(
					'table' => 'infotablesin_options',
					'rows'  => 3,
					'bytes' => 0,
				),
				array(
					'table' => 'infotablesin_terms',
					'rows'  => 2,
					'bytes' => 0,
				),
			),
			$rows
		);

		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotablesin_options"', $count_sql );
		$this->assertStringContainsString( '"infotablesin_terms"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesin_noise"', $count_sql );

		$driver->query( "INSERT INTO infotablesin_terms (name) VALUES ('three')" );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows',
					SUM(data_length + index_length) AS 'bytes'
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME IN ('infotablesin_options','infotablesin_terms')
				GROUP BY TABLE_NAME
				ORDER BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( 3, $rows[1]['rows'] );
		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotablesin_options"', $count_sql );
		$this->assertStringContainsString( '"infotablesin_terms"', $count_sql );
		$this->assertStringNotContainsString( '"infotablesin_noise"', $count_sql );
	}

	public function test_information_schema_tables_wildcard_and_complex_predicates_fall_back_to_full_refresh(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE infotableswildplain (id INT, name TEXT)' );
		$driver->query( 'CREATE TABLE infotableswildnoise (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT)' );
		$driver->query( 'CREATE TABLE infotableswildother (id INT)' );
		$driver->query( "INSERT INTO infotableswildplain VALUES (1, 'one'), (2, 'two')" );
		$driver->query( "INSERT INTO infotableswildnoise (name) VALUES ('noise')" );
		$driver->query( 'INSERT INTO infotableswildother VALUES (1)' );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, TABLE_ROWS
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME LIKE 'infotableswild%'
				ORDER BY TABLE_NAME"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array( 'infotableswildnoise', 'infotableswildother', 'infotableswildplain' ),
			array_column( $rows, 'TABLE_NAME' )
		);

		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotableswildplain"', $count_sql );
		$this->assertStringContainsString( '"infotableswildnoise"', $count_sql );
		$this->assertStringContainsString( '"infotableswildother"', $count_sql );
		$this->assertGreaterThanOrEqual( 1, $this->count_duckdb_currval_queries( $queries ) );

		$queries = array();
		$rows    = $driver->query(
			"SELECT TABLE_NAME, TABLE_ROWS
				FROM information_schema.tables
				WHERE TABLE_SCHEMA = 'wp'
					AND LOWER(TABLE_NAME) = 'infotableswildplain'"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'infotableswildplain' ), array_column( $rows, 'TABLE_NAME' ) );
		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"infotableswildplain"', $count_sql );
		$this->assertStringContainsString( '"infotableswildnoise"', $count_sql );
		$this->assertStringContainsString( '"infotableswildother"', $count_sql );
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
		$driver->query(
			"INSERT INTO plain (id, name)
			VALUES (1, 'one'), (2, 'two'), (3, 'three')"
		);

		$status = $driver->query( 'SHOW TABLE STATUS FROM wp' );
		$this->assertSame( 0, $status->rowCount() );
		$this->assertSame( array( 'name' => 'Name' ), $status->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => 'Engine' ), $status->getColumnMeta( 1 ) );
		$this->assertSame( array( 'name' => 'Comment' ), $status->getColumnMeta( 17 ) );

		$rows = $status->fetchAll( PDO::FETCH_ASSOC );

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
		$this->assertSame( 2, $rows[0]['Rows'] );
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
		$this->assertSame( 3, $rows[1]['Rows'] );
		$this->assertSame( null, $rows[1]['Auto_increment'] );

		$like = $driver->query( "SHOW TABLE STATUS IN wp LIKE 'plain'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'plain' ), array_column( $like, 'Name' ) );
		$this->assertSame( 3, $like[0]['Rows'] );

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

		$driver->query( 'SHOW TABLE STATUS FROM wp' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array( array( 'found_rows' => 2 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_show_table_status_exact_like_materializes_only_matching_table(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE statusexactplain (id INT, name TEXT)' );
		$driver->query( 'CREATE TABLE statusexactnoise (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT)' );
		$driver->query( 'CREATE TABLE statusexactother (id INT)' );
		$driver->query( "INSERT INTO statusexactplain VALUES (1, 'one'), (2, 'two')" );
		$driver->query( "INSERT INTO statusexactnoise (name) VALUES ('noise')" );
		$driver->query( 'INSERT INTO statusexactother VALUES (1)' );

		$queries = array();
		$rows    = $driver->query( "SHOW TABLE STATUS LIKE 'statusexactplain'" )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame( array( 'statusexactplain' ), array_column( $rows, 'Name' ) );
		$this->assertSame( 2, $rows[0]['Rows'] );
		$this->assertSame( 1, $this->count_duckdb_table_row_count_queries( $queries ) );

		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"statusexactplain"', $count_sql );
		$this->assertStringNotContainsString( '"statusexactnoise"', $count_sql );
		$this->assertStringNotContainsString( '"statusexactother"', $count_sql );
		$this->assertSame( 0, $this->count_duckdb_currval_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$missing = $driver->query( "SHOW TABLE STATUS LIKE 'statusexactmissing'" );
		$this->assertSame( 0, $missing->rowCount() );
		$this->assertSame( array( 'name' => 'Name' ), $missing->getColumnMeta( 0 ) );
		$this->assertSame( array(), $missing->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( 0, $this->count_duckdb_table_row_count_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_currval_queries( $queries ) );
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$queries = array();
		$rows    = $driver->query( "SHOW TABLE STATUS LIKE 'statusexact%'" )->fetchAll( PDO::FETCH_ASSOC );

		$this->assertSame(
			array( 'statusexactnoise', 'statusexactother', 'statusexactplain' ),
			array_column( $rows, 'Name' )
		);
		$count_sql = implode( "\n", $this->duckdb_table_row_count_queries( $queries ) );
		$this->assertStringContainsString( '"statusexactplain"', $count_sql );
		$this->assertStringContainsString( '"statusexactnoise"', $count_sql );
		$this->assertStringContainsString( '"statusexactother"', $count_sql );
		$this->assertGreaterThanOrEqual( 1, $this->count_duckdb_currval_queries( $queries ) );
	}

	public function test_check_table_returns_mysql_shaped_status_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE check_items (id INT)' );
		$driver->query( 'CREATE TABLE check_second (id INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE check_temp_only (id INT)' );
		$driver->query( 'INSERT INTO check_items VALUES (1)' );

		$driver->query( 'SELECT id FROM check_items' );
		$this->assertSame(
			array( array( 'found_rows' => 1 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$check = $driver->query( 'CHECK TABLE check_items' );
		$this->assertSame( 4, $check->columnCount() );
		$this->assertSame( array( 'name' => 'Table' ), $check->getColumnMeta( 0 ) );
		$this->assertSame( array( 'name' => 'Op' ), $check->getColumnMeta( 1 ) );
		$this->assertSame( array( 'name' => 'Msg_type' ), $check->getColumnMeta( 2 ) );
		$this->assertSame( array( 'name' => 'Msg_text' ), $check->getColumnMeta( 3 ) );
		$this->assertSame( 0, $check->rowCount() );
		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$check->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'found_rows' => 0 ) ),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$driver->query( 'CHECK TABLE wp.check_items' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$driver->query( 'CHECK TABLES check_items' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$driver->query(
				'CHECK TABLE check_items QUICK FAST MEDIUM EXTENDED CHANGED FOR UPGRADE'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				array(
					'Table'    => 'wp.check_second',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$driver->query( 'CHECK TABLE check_items, check_second' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_temp_only',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$driver->query( 'CHECK TABLE check_temp_only' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(),
			$driver->query(
				"SELECT TABLE_NAME
				FROM information_schema.tables
				WHERE TABLE_NAME = 'check_temp_only'
					OR TABLE_NAME LIKE '__wp_duckdb_%'
				ORDER BY TABLE_NAME"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.missing_check_table',
					'Op'       => 'check',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing_check_table' doesn't exist",
				),
				array(
					'Table'    => 'wp.missing_check_table',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$driver->query( 'CHECK TABLE missing_check_table' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Table'    => 'wp.check_items',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				array(
					'Table'    => 'wp.missing_check_table',
					'Op'       => 'check',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing_check_table' doesn't exist",
				),
				array(
					'Table'    => 'wp.missing_check_table',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$driver->query( 'CHECK TABLE check_items, missing_check_table' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_administration_table_statements_return_mysql_shaped_status_rows(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE admin_items (id INT)' );
		$driver->query( 'CREATE TABLE admin_second (id INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE admin_temp_only (id INT)' );
		$driver->query( 'CREATE TABLE admin_shadow (base_id INT)' );
		$driver->query( 'CREATE TEMPORARY TABLE admin_shadow (temp_id INT)' );
		$driver->query( 'INSERT INTO admin_items VALUES (1)' );
		$driver->query( 'INSERT INTO admin_shadow VALUES (9)' );

		foreach (
			array(
				'ANALYZE TABLE'  => 'analyze',
				'OPTIMIZE TABLE' => 'optimize',
				'REPAIR TABLE'   => 'repair',
			) as $statement => $operation
		) {
			$driver->query( 'SELECT id FROM admin_items' );
			$this->assertSame(
				array( array( 'found_rows' => 1 ) ),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$result = $driver->query( $statement . ' admin_items' );
			$this->assertSame( 4, $result->columnCount() );
			$this->assertSame( array( 'name' => 'Table' ), $result->getColumnMeta( 0 ) );
			$this->assertSame( array( 'name' => 'Op' ), $result->getColumnMeta( 1 ) );
			$this->assertSame( array( 'name' => 'Msg_type' ), $result->getColumnMeta( 2 ) );
			$this->assertSame( array( 'name' => 'Msg_text' ), $result->getColumnMeta( 3 ) );
			$this->assertSame( 0, $result->rowCount() );
			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$result->fetchAll( PDO::FETCH_ASSOC )
			);
			$this->assertSame(
				array( array( 'found_rows' => 0 ) ),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( $statement . ' wp.admin_items' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( str_replace( ' TABLE', ' TABLES', $statement ) . ' admin_items' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
					array(
						'Table'    => 'wp.admin_second',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( $statement . ' admin_items, admin_second' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_temp_only',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( $statement . ' admin_temp_only' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_shadow',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( $statement . ' admin_shadow' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.missing_admin_table',
						'Op'       => $operation,
						'Msg_type' => 'Error',
						'Msg_text' => "Table 'missing_admin_table' doesn't exist",
					),
					array(
						'Table'    => 'wp.missing_admin_table',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'Operation failed',
					),
				),
				$driver->query( $statement . ' missing_admin_table' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
					array(
						'Table'    => 'wp.missing_admin_table',
						'Op'       => $operation,
						'Msg_type' => 'Error',
						'Msg_text' => "Table 'missing_admin_table' doesn't exist",
					),
					array(
						'Table'    => 'wp.missing_admin_table',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'Operation failed',
					),
				),
				$driver->query( $statement . ' admin_items, missing_admin_table' )->fetchAll( PDO::FETCH_ASSOC )
			);
		}

		foreach (
			array(
				'ANALYZE LOCAL TABLE admin_items'   => 'analyze',
				'ANALYZE NO_WRITE_TO_BINLOG TABLES admin_items' => 'analyze',
				'OPTIMIZE LOCAL TABLE admin_items'  => 'optimize',
				'OPTIMIZE NO_WRITE_TO_BINLOG TABLES admin_items' => 'optimize',
				'REPAIR LOCAL TABLE admin_items'    => 'repair',
				'REPAIR NO_WRITE_TO_BINLOG TABLES admin_items' => 'repair',
				'REPAIR TABLE admin_items QUICK'    => 'repair',
				'REPAIR TABLE admin_items EXTENDED' => 'repair',
				'REPAIR TABLE admin_items USE_FRM'  => 'repair',
				'REPAIR TABLE admin_items QUICK EXTENDED USE_FRM' => 'repair',
				'ANALYZE TABLE admin_items UPDATE HISTOGRAM ON id' => 'analyze',
				'ANALYZE TABLE admin_items DROP HISTOGRAM ON id' => 'analyze',
			) as $sql => $operation
		) {
			$this->assertSame(
				array(
					array(
						'Table'    => 'wp.admin_items',
						'Op'       => $operation,
						'Msg_type' => 'status',
						'Msg_text' => 'OK',
					),
				),
				$driver->query( $sql )->fetchAll( PDO::FETCH_ASSOC )
			);
		}

		$this->assertSame(
			array(),
			$driver->query(
				"SELECT TABLE_NAME
				FROM information_schema.tables
				WHERE TABLE_NAME = 'admin_temp_only'
					OR TABLE_NAME LIKE '__wp_duckdb_%'
				ORDER BY TABLE_NAME"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array( array( 'temp_id' => 9 ) ),
			$driver->query( 'SELECT temp_id FROM admin_shadow' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_check_table_rejects_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE check_items (id INT)' );

		foreach (
			array(
				'CHECK TABLE check_items QUICK junk'      => 'DuckDB driver could not parse MySQL statement',
				'CHECK TABLE check_items FOR'             => 'DuckDB driver could not parse MySQL statement',
				'CHECK TABLE other_database.check_items'  => 'Only the current database is supported',
				'CHECK TABLE __wp_duckdb_column_metadata' => 'Internal DuckDB metadata tables cannot be modified',
				'CHECK TABLE information_schema.tables'   =>
					"Access denied for user 'duckdb'@'%' to database 'information_schema'",
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected CHECK TABLE rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}
		}
	}

	public function test_administration_table_statements_reject_unsupported_targets(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE admin_items (id INT)' );

		foreach ( array( 'ANALYZE TABLE', 'OPTIMIZE TABLE', 'REPAIR TABLE' ) as $statement ) {
			foreach (
				array(
					$statement . ' other_database.admin_items'  => 'Only the current database is supported',
					$statement . ' __wp_duckdb_column_metadata' => 'Internal DuckDB metadata tables cannot be modified',
					$statement . ' information_schema.tables'   =>
						"Access denied for user 'duckdb'@'%' to database 'information_schema'",
					$statement . ' admin_items, information_schema.tables' =>
						"Access denied for user 'duckdb'@'%' to database 'information_schema'",
				) as $sql => $message
			) {
				try {
					$driver->query( $sql );
					$this->fail( 'Expected table administration rejection for SQL: ' . $sql );
				} catch ( WP_DuckDB_Driver_Exception $e ) {
					$this->assertStringContainsString( $message, $e->getMessage() );
				}
			}
		}

		try {
			$driver->query( 'OPTIMIZE TABLE admin_items QUICK' );
			$this->fail( 'Expected OPTIMIZE TABLE trailing option rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'could not parse MySQL statement', $e->getMessage() );
		}
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
SQL
			,
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
SQL
			,
			$composite_rows[0]['Create Table']
		);

		$plain_rows = $driver->query( 'SHOW CREATE TABLE plain' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			<<<'SQL'
CREATE TABLE `plain` (
  `id` int DEFAULT NULL,
  `name` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL
			,
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
		$this->assertSame( array(), $driver->query( 'SHOW FULL TABLES' )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( array(), $driver->query( "SHOW FULL TABLES LIKE 'temp_items'" )->fetchAll( PDO::FETCH_ASSOC ) );
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

	public function test_temporary_column_metadata_recovers_after_internal_table_disappears(): void {
		$this->requireDuckDBRuntime();

		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$driver     = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query( 'CREATE TEMPORARY TABLE temp_metadata_seed (id INT, name VARCHAR(20))' );

		$connection->query( 'DROP TABLE "__wp_duckdb_temp_column_metadata"' );

		$driver->query(
			'CREATE TEMPORARY TABLE temp_metadata_recovered (
				id INT,
				label VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$columns = array_column(
			$driver->query( 'SHOW FULL COLUMNS FROM temp_metadata_recovered' )->fetchAll( PDO::FETCH_ASSOC ),
			null,
			'Field'
		);

		$this->assertSame( 'int', $columns['id']['Type'] );
		$this->assertSame( 'varchar(20)', $columns['label']['Type'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $columns['label']['Collation'] );
	}

	public function test_temporary_metadata_tables_are_reensured_after_rollback(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'BEGIN' );
		$driver->query(
			'CREATE TEMPORARY TABLE rollback_temp_metadata (
				c VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);
		$driver->query( 'ROLLBACK' );

		$driver->query(
			'CREATE TEMPORARY TABLE temp_metadata_after_rollback (
				c VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$create_queries = $driver->get_last_duckdb_queries();
		$this->assertNotEmpty(
			array_filter(
				$create_queries,
				function ( string $sql ): bool {
					return false !== strpos( $sql, 'CREATE TEMP TABLE IF NOT EXISTS "__wp_duckdb_temp_column_metadata"' );
				}
			)
		);
		$this->assertNotEmpty(
			array_filter(
				$create_queries,
				function ( string $sql ): bool {
					return false !== strpos( $sql, 'CREATE TEMP TABLE IF NOT EXISTS "__wp_duckdb_temp_table_metadata"' );
				}
			)
		);

		$columns = $driver->query( 'SHOW FULL COLUMNS FROM temp_metadata_after_rollback' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( 'c', $columns[0]['Field'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $columns[0]['Collation'] );

		try {
			$driver->query( 'SHOW FULL COLUMNS FROM missing_temp_table' );
			$this->fail( 'Expected missing user table to remain an error.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DuckDB table does not exist: missing_temp_table.', $e->getMessage() );
		}
	}

	public function test_repeated_metadata_reads_do_not_reensure_metadata_tables(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE metadata_cache_items (id INT NOT NULL, name VARCHAR(20), KEY name_key (name))' );

		$first = $driver->query( 'SHOW INDEX FROM metadata_cache_items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'name_key' ), array_column( $first, 'Key_name' ) );

		$second = $driver->query( 'SHOW INDEX FROM metadata_cache_items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( $first, $second );

		foreach ( $driver->get_last_duckdb_queries() as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'CREATE TABLE IF NOT EXISTS "__wp_duckdb_index_metadata"', $duckdb_sql );
			$this->assertStringNotContainsString( "pragma_table_info('__wp_duckdb_index_metadata')", $duckdb_sql );
		}

		$driver->query( 'CREATE TEMPORARY TABLE temp_metadata_cache_items (id INT NOT NULL, name VARCHAR(20), KEY temp_name_key (name))' );
		$temp_first = $driver->query( 'SHOW INDEX FROM temp_metadata_cache_items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'temp_name_key' ), array_column( $temp_first, 'Key_name' ) );

		$temp_second = $driver->query( 'SHOW INDEX FROM temp_metadata_cache_items' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( $temp_first, $temp_second );

		foreach ( $driver->get_last_duckdb_queries() as $duckdb_sql ) {
			$this->assertStringNotContainsString( 'CREATE TEMP TABLE IF NOT EXISTS "__wp_duckdb_temp_index_metadata"', $duckdb_sql );
			$this->assertStringNotContainsString( "pragma_table_info('__wp_duckdb_temp_index_metadata')", $duckdb_sql );
		}
	}

	public function test_repeated_simple_select_metadata_uses_schema_cache_and_invalidates_after_alter(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE metadata_query_cache_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(20) NOT NULL,
				PRIMARY KEY (id)
			)'
		);

		$queries = array();
		$result  = $driver->query( 'SELECT id, name FROM metadata_query_cache_items WHERE id = 0 ORDER BY id' );
		$this->assertSame( 2, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$result = $driver->query( 'SELECT id, name FROM metadata_query_cache_items WHERE id = 0 ORDER BY id' );
		$this->assertSame( 2, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'metadata_query_cache_items' ) );

		$driver->query( "ALTER TABLE metadata_query_cache_items ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'" );

		$queries = array();
		$result  = $driver->query( 'SELECT id, name, status FROM metadata_query_cache_items WHERE id = 0 ORDER BY id' );
		$this->assertSame( 3, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$result = $driver->query( 'SELECT id, name, status FROM metadata_query_cache_items WHERE id = 0 ORDER BY id' );
		$this->assertSame( 3, $result->columnCount() );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( 1, $this->count_duckdb_column_metadata_queries( $queries, 'metadata_query_cache_items' ) );
	}

	public function test_repeated_simple_select_reuses_table_resolution_cache(): void {
		$this->requireDuckDBRuntime();

		$queries    = array();
		$connection = new WP_DuckDB_Connection( array( 'path' => ':memory:' ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		$driver = new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE table_resolution_cache_items (
				option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				item_name VARCHAR(191) NOT NULL,
				item_value LONGTEXT NOT NULL,
				PRIMARY KEY (option_id),
				UNIQUE KEY item_name (item_name)
			)'
		);

		$queries = array();
		$this->assertSame(
			array(),
			$driver->query( "SELECT item_value FROM table_resolution_cache_items WHERE item_name = 'missing' LIMIT 1" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 2, $this->count_duckdb_table_resolution_queries( $queries ) );

		$queries = array();
		$this->assertSame(
			array(),
			$driver->query( "SELECT item_value FROM table_resolution_cache_items WHERE item_name = 'missing' LIMIT 1" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
	}

	public function test_wordpress_options_autoload_select_reuses_visible_table_cache_after_unrelated_persistent_schema_change(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$this->assert_wordpress_options_autoload_rows( $driver );
		$driver->query( 'CREATE TABLE unrelated_options_runtime_probe (id INT)' );

		$queries = array();
		$result  = $driver->query( $this->wordpress_options_autoload_select_sql() );
		$this->assert_wordpress_options_autoload_result( $result );
		$this->assert_wordpress_options_autoload_select_used_one_native_query( $queries );
	}

	public function test_wordpress_options_autoload_select_reuses_visible_table_cache_after_unrelated_temporary_schema_change(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$this->assert_wordpress_options_autoload_rows( $driver );
		$driver->query( 'CREATE TEMPORARY TABLE unrelated_temp_options_runtime_probe (id INT)' );

		$queries = array();
		$result  = $driver->query( $this->wordpress_options_autoload_select_sql() );
		$this->assert_wordpress_options_autoload_result( $result );
		$this->assert_wordpress_options_autoload_select_used_one_native_query( $queries );
	}

	public function test_wordpress_options_autoload_fast_path_uses_one_native_query_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-alloptions-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_options_autoload_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$queries = array();
			$result  = $driver->query( $this->wordpress_options_autoload_select_sql() );
			$this->assert_wordpress_options_autoload_result( $result );
			$this->assert_wordpress_options_autoload_select_used_one_native_query( $queries );
			$this->assertCount( 1, $driver->get_last_duckdb_queries() );

			$this->assertSame(
				array(
					array(
						'found_rows' => 3,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_options_autoload_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_options (
				option_name VARCHAR(191) NOT NULL,
				option_value VARCHAR(191) NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('temporary_name', 'temporary_value', 'yes')"
		);

		$queries = array();
		$result  = $driver->query( $this->wordpress_options_autoload_select_sql() );
		$this->assertSame(
			array(
				array(
					'option_name'  => 'temporary_name',
					'option_value' => 'temporary_value',
				),
			),
			$this->sorted_wordpress_options_autoload_rows( $result )
		);
		$this->assert_wordpress_options_autoload_select_used_one_native_query( $queries );

		$this->assertSame(
			array(
				array(
					'found_rows' => 1,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_options' );
		$this->assert_wordpress_options_autoload_rows( $driver );
	}

	public function test_wordpress_options_single_option_fast_path_uses_one_native_query_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-single-option-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_options_autoload_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$queries = array();
			$result  = $driver->query( "SELECT autoload FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
			$this->assertSame( array( array( 'autoload' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );
			$this->assert_wordpress_options_single_option_metadata( $result, 'autoload' );

			$queries = array();
			$result  = $driver->query( "SELECT autoload FROM wptests_options WHERE option_name = 'siteurl'" );
			$this->assertSame( array( array( 'autoload' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );
			$this->assert_wordpress_options_single_option_metadata( $result, 'autoload' );

			$queries = array();
			$result  = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
			$this->assertSame( array( array( 'option_value' => 'https://example.test' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );
			$this->assert_wordpress_options_single_option_metadata( $result, 'option_value' );

			$this->assertSame(
				array(
					array(
						'found_rows' => 1,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_options_single_option_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_options (
				option_name VARCHAR(191) NOT NULL,
				option_value VARCHAR(191) NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('siteurl', 'temporary_value', 'no')"
		);

		$queries = array();
		$result  = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
		$this->assertSame( array( array( 'option_value' => 'temporary_value' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );

		$driver->query( 'DROP TEMPORARY TABLE wptests_options' );
		$result = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
		$this->assertSame( array( array( 'option_value' => 'https://example.test' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
	}

	public function test_wordpress_options_single_option_fast_path_uses_case_insensitive_option_name_comparison(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$queries = array();
		$result  = $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'SITEURL' LIMIT 1" );
		$this->assertSame( array( array( 'option_value' => 'https://example.test' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );
		$this->assertStringContainsString( 'lower("option_name")', $queries[0] );
	}

	public function test_wordpress_options_single_option_fast_path_decodes_mysql_escaped_literal(): void {
		$this->requireDuckDBRuntime();

		$queries     = array();
		$driver      = $this->query_logged_duckdb_driver( $queries );
		$option_name = "quote'key\\slash";
		$this->create_wordpress_options_autoload_fixture( $driver );
		$driver->query(
			'INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('
			. $this->mysql_single_quoted_literal( $option_name )
			. ", 'escaped-value', 'yes')"
		);

		$queries = array();
		$result  = $driver->query(
			'SELECT option_value FROM wptests_options WHERE option_name = '
			. $this->mysql_single_quoted_literal( $option_name )
			. ' LIMIT 1'
		);
		$this->assertSame( array( array( 'option_value' => 'escaped-value' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assert_wordpress_options_single_option_select_used_one_native_query( $queries );
	}

	public function test_wordpress_options_single_option_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$queries = array();
		$result  = $driver->query( "SELECT SQL_CALC_FOUND_ROWS autoload FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
		$this->assertSame( array( array( 'autoload' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertGreaterThan( 1, count( $queries ), implode( "\n", $queries ) );
		$this->assertTrue(
			count(
				array_filter(
					$queries,
					function ( string $query ): bool {
						return false !== strpos( $query, '__wp_duckdb_found_rows' );
					}
				)
			) > 0,
			implode( "\n", $queries )
		);

		$queries = array();
		$result  = $driver->query( "SELECT autoload AS a FROM wptests_options WHERE option_name = 'siteurl' LIMIT 1" );
		$this->assertSame( array( array( 'a' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame( 'a', $result->getColumnMeta( 0 )['name'] );
		$this->assertStringContainsString( ' AS a ', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query( "SELECT autoload FROM wptests_options WHERE option_name = 'siteurl' AND autoload = 'yes' LIMIT 1" );
		$this->assertSame( array( array( 'autoload' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertStringContainsString( "autoload = 'yes'", implode( "\n", $queries ) );

		$driver->query(
			'CREATE TABLE notoptions (
				option_name VARCHAR(191) NOT NULL,
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query( "INSERT INTO notoptions (option_name, option_value, autoload) VALUES ('siteurl', 'value', 'yes')" );

		$queries = array();
		$result  = $driver->query( "SELECT autoload FROM notoptions WHERE option_name = 'siteurl' LIMIT 1" );
		$this->assertSame( array( array( 'autoload' => 'yes' ) ), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertStringContainsString( 'notoptions', implode( "\n", $queries ) );
		$this->assertStringNotContainsString( 'lower("option_name")', implode( "\n", $queries ) );
	}

	public function test_wordpress_options_update_fast_path_uses_one_native_query_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-option-update-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_options_autoload_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$option_value = "changed value; with spaces and a quote ' marker";
			$queries      = array();
			$result       = $driver->query(
				'UPDATE wptests_options SET option_value = '
				. $this->mysql_single_quoted_literal( $option_value )
				. " WHERE option_name = 'siteurl'"
			);
			$this->assertSame( 1, $result->rowCount() );
			$this->assertSame( 0, $driver->get_insert_id() );
			$this->assert_wordpress_options_update_used_one_native_query( $queries );
			$this->assertSame(
				array(
					array(
						'found_rows' => 0,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
			$this->assertSame(
				array( array( 'option_value' => $option_value ) ),
				$driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl'" )->fetchAll( PDO::FETCH_ASSOC )
			);

			$queries = array();
			$result  = $driver->query(
				'UPDATE wptests_options SET option_value = '
				. $this->mysql_single_quoted_literal( $option_value )
				. " WHERE option_name = 'siteurl'"
			);
			$this->assertSame( 0, $result->rowCount() );
			$this->assert_wordpress_options_update_used_one_native_query( $queries );

			$queries = array();
			$result  = $driver->query( "UPDATE wptests_options SET option_value = 'case updated' WHERE option_name = 'SITEURL'" );
			$this->assertSame( 1, $result->rowCount() );
			$this->assert_wordpress_options_update_used_one_native_query( $queries );
			$this->assertSame(
				array( array( 'option_value' => 'case updated' ) ),
				$driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl'" )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_options_update_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_options (
				option_name VARCHAR(191) NOT NULL,
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('siteurl', 'temporary_value', 'yes')"
		);

		$queries = array();
		$result  = $driver->query( "UPDATE `wptests_options` SET `option_value` = 'temporary updated' WHERE `option_name` = 'siteurl'" );
		$this->assertSame( 1, $result->rowCount() );
		$this->assert_wordpress_options_update_used_one_native_query( $queries );
		$this->assertSame(
			array( array( 'option_value' => 'temporary updated' ) ),
			$driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_options' );
		$this->assertSame(
			array( array( 'option_value' => 'https://example.test' ) ),
			$driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'siteurl'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_options_update_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$queries = array();
		$result  = $driver->query(
			"UPDATE wptests_options SET option_value = 'changed' WHERE option_name = 'siteurl' AND autoload = 'yes'"
		);
		$this->assertSame( 1, $result->rowCount() );
		$this->assertStringContainsString( "autoload = 'yes'", implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"UPDATE wptests_options SET option_value = 'changed again', autoload = 'no' WHERE option_name = 'siteurl'"
		);
		$this->assertSame( 1, $result->rowCount() );
		$this->assertStringContainsString( 'autoload', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"UPDATE wptests_options SET option_value = 'qualified' WHERE wptests_options.option_name = 'siteurl'"
		);
		$this->assertSame( 1, $result->rowCount() );
		$this->assertStringContainsString( 'wptests_options.option_name', implode( "\n", $queries ) );

		$driver->query(
			'CREATE TABLE option_items (
				option_name VARCHAR(191) NOT NULL,
				option_value LONGTEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO option_items (option_name, option_value) VALUES ('siteurl', 'value')" );

		$queries = array();
		$result  = $driver->query( "UPDATE option_items SET option_value = 'generic' WHERE option_name = 'siteurl'" );
		$this->assertSame( 1, $result->rowCount() );
		$this->assertStringContainsString( 'option_items', implode( "\n", $queries ) );
		$this->assertStringNotContainsString( 'UPDATE "wptests_options"', implode( "\n", $queries ) );
	}

	public function test_wordpress_posts_id_lookup_fast_path_uses_schema_metadata_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-post-id-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_posts_id_lookup_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$queries = array();
			$result  = $driver->query( 'SELECT * FROM wptests_posts WHERE ID = 1 LIMIT 1' );
			$this->assertSame(
				array(
					array(
						'ID'          => 1,
						'post_author' => 10,
						'post_date'   => '2026-01-01 00:00:00',
						'post_title'  => 'First',
						'post_type'   => 'post',
					),
				),
				$result->fetchAll( PDO::FETCH_ASSOC )
			);
			$this->assert_wordpress_posts_id_lookup_metadata( $result, 'wptests_posts' );
			$this->assertStringContainsString( 'SELECT * FROM "wptests_posts" WHERE "ID" = 1 LIMIT 1', end( $queries ) );

			$queries = array();
			$result  = $driver->query( "SELECT * FROM wptests_posts WHERE ID = '2' LIMIT 1" );
			$this->assertSame(
				array(
					array(
						'ID'          => 2,
						'post_author' => 20,
						'post_date'   => '2026-02-01 00:00:00',
						'post_title'  => 'Second',
						'post_type'   => 'page',
					),
				),
				$result->fetchAll( PDO::FETCH_ASSOC )
			);
			$this->assert_wordpress_posts_id_lookup_select_used_one_native_query( $queries, 2 );
			$this->assert_wordpress_posts_id_lookup_metadata( $result, 'wptests_posts' );

			$queries = array();
			$result  = $driver->query( 'SELECT * FROM wptests_posts WHERE ID = 999 LIMIT 1' );
			$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_wordpress_posts_id_lookup_select_used_one_native_query( $queries, 999 );
			$this->assertSame(
				array(
					array(
						'found_rows' => 0,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_posts_id_lookup_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_posts_id_lookup_fixture( $driver );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				temp_title VARCHAR(191) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query( "INSERT INTO wptests_posts (ID, temp_title) VALUES (1, 'temporary')" );

		$queries = array();
		$result  = $driver->query( 'SELECT * FROM wptests_posts WHERE ID = 1 LIMIT 1' );
		$this->assertSame(
			array(
				array(
					'ID'         => 1,
					'temp_title' => 'temporary',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_posts_id_lookup_select_used_one_native_query( $queries, 1 );
		$this->assertSame( 'temp_title', $result->getColumnMeta( 1 )['name'] );
		$this->assertSame( 'wptests_posts', $result->getColumnMeta( 1 )['mysqli:orgtable'] );

		$this->assertSame(
			array(
				array(
					'found_rows' => 1,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_posts' );
		$result = $driver->query( 'SELECT * FROM wptests_posts WHERE ID = 1 LIMIT 1' );
		$this->assertSame( 'First', $result->fetchAll( PDO::FETCH_ASSOC )[0]['post_title'] );
	}

	public function test_wordpress_posts_id_lookup_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_posts_id_lookup_fixture( $driver );

		$queries = array();
		$result  = $driver->query( 'SELECT SQL_CALC_FOUND_ROWS * FROM wptests_posts WHERE ID = 1 LIMIT 1' );
		$this->assertSame( 1, $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
		$this->assertTrue(
			count(
				array_filter(
					$queries,
					function ( string $query ): bool {
						return false !== strpos( $query, '__wp_duckdb_found_rows' );
					}
				)
			) > 0,
			implode( "\n", $queries )
		);

		$queries = array();
		$result  = $driver->query( 'SELECT p.* FROM wptests_posts AS p WHERE p.ID = 1 LIMIT 1' );
		$this->assertSame( 1, $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
		$this->assertSame( 'p', $result->getColumnMeta( 0 )['table'] );

		$queries = array();
		$result  = $driver->query( "SELECT * FROM wptests_posts WHERE ID = 1 AND post_type = 'page' LIMIT 1" );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertStringContainsString( 'post_type', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query( 'SELECT * FROM wptests_posts WHERE ID = 1 LIMIT 2' );
		$this->assertCount( 1, $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertStringContainsString( 'LIMIT 2', implode( "\n", $queries ) );

		$driver->query(
			'CREATE TABLE post_lookup_items (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_title VARCHAR(191) NOT NULL DEFAULT \'\',
				PRIMARY KEY (ID)
			)'
		);
		$driver->query( "INSERT INTO post_lookup_items (ID, post_title) VALUES (1, 'not posts')" );

		$queries = array();
		$result  = $driver->query( 'SELECT * FROM post_lookup_items WHERE ID = 1 LIMIT 1' );
		$this->assertSame( 'not posts', $result->fetchAll( PDO::FETCH_ASSOC )[0]['post_title'] );
		$this->assertStringContainsString( 'post_lookup_items', implode( "\n", $queries ) );
	}

	public function test_wordpress_posts_slug_status_lookup_fast_path_uses_one_native_query_after_metadata_warmup(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-post-slug-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_posts_slug_status_lookup_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$queries = array();
			$result  = $driver->query( $this->wordpress_posts_slug_status_lookup_select_sql() );
			$this->assertSame(
				array(
					array(
						'ID' => 5,
					),
					array(
						'ID' => 6,
					),
					array(
						'ID' => 3,
					),
					array(
						'ID' => 1,
					),
				),
				array_map(
					function ( array $row ): array {
						return array( 'ID' => (int) $row['ID'] );
					},
					$result->fetchAll( PDO::FETCH_ASSOC )
				)
			);
			$this->assert_wordpress_posts_slug_status_lookup_metadata(
				$result,
				'wptests_posts',
				array( 'ID', 'post_author', 'post_date', 'post_title', 'post_name', 'post_type', 'post_status' )
			);

			$queries = array();
			$result  = $driver->query( $this->wordpress_posts_slug_status_lookup_select_sql() );
			$this->assertSame(
				array( 5, 6, 3, 1 ),
				array_map(
					'intval',
					array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'ID' )
				)
			);
			$this->assert_wordpress_posts_slug_status_lookup_select_used_one_native_query( $queries );

			$this->assertSame(
				array(
					array(
						'found_rows' => 4,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_posts_slug_status_lookup_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_posts_slug_status_lookup_fixture( $driver );

		$driver->query(
			"CREATE TEMPORARY TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_name VARCHAR(200) NOT NULL DEFAULT '',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				temp_title VARCHAR(191) NOT NULL DEFAULT '',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_name, post_type, post_status, temp_title) VALUES
				(1, '2026-06-01 00:00:00', 'target-slug', 'post', 'publish', 'temporary old'),
				(2, '2026-06-02 00:00:00', 'target-slug', 'post', 'publish', 'temporary excluded'),
				(3, '2026-06-03 00:00:00', 'target-slug', 'page', 'publish', 'temporary new')"
		);

		$queries = array();
		$result  = $driver->query( $this->wordpress_posts_slug_status_lookup_select_sql() );
		$this->assertSame(
			array(
				array(
					'ID'         => 3,
					'temp_title' => 'temporary new',
				),
				array(
					'ID'         => 1,
					'temp_title' => 'temporary old',
				),
			),
			array_map(
				function ( array $row ): array {
					return array(
						'ID'         => (int) $row['ID'],
						'temp_title' => $row['temp_title'],
					);
				},
				$result->fetchAll( PDO::FETCH_ASSOC )
			)
		);
		$this->assert_wordpress_posts_slug_status_lookup_select_used_one_native_query( $queries );
		$this->assert_wordpress_posts_slug_status_lookup_metadata(
			$result,
			'wptests_posts',
			array( 'ID', 'post_date', 'post_name', 'post_type', 'post_status', 'temp_title' )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_posts' );
		$result = $driver->query( $this->wordpress_posts_slug_status_lookup_select_sql() );
		$this->assertSame( 5, (int) $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
	}

	public function test_wordpress_posts_slug_status_lookup_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_posts_slug_status_lookup_fixture( $driver );

		$queries = array();
		$result  = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.* FROM wptests_posts
			WHERE 1=1
				AND wptests_posts.post_name = 'target-slug'
				AND wptests_posts.ID NOT IN (2)
				AND wptests_posts.post_type IN ('post', 'page', 'attachment')
				AND ((wptests_posts.post_status = 'publish'))
			ORDER BY wptests_posts.post_date DESC"
		);
		$this->assertSame( 5, (int) $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
		$this->assertStringContainsString( '__wp_duckdb_found_rows', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT p.* FROM wptests_posts AS p
			WHERE 1=1
				AND p.post_name = 'target-slug'
				AND p.ID NOT IN (2)
				AND p.post_type IN ('post', 'page', 'attachment')
				AND ((p.post_status = 'publish'))
			ORDER BY p.post_date DESC"
		);
		$this->assertSame( 5, (int) $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
		$this->assertSame( 'p', $result->getColumnMeta( 0 )['table'] );
		$this->assertStringNotContainsString( 'SELECT "wptests_posts".* FROM "wptests_posts"', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT wptests_posts.* FROM wptests_posts
			WHERE 1=1
				AND wptests_posts.post_name = 'target-slug'
				AND wptests_posts.post_type IN ('post', 'page', 'attachment')
				AND ((wptests_posts.post_status = 'publish'))
			ORDER BY wptests_posts.post_date DESC"
		);
		$this->assertSame( 2, (int) $result->fetchAll( PDO::FETCH_ASSOC )[0]['ID'] );
		$this->assertStringNotContainsString( 'SELECT "wptests_posts".* FROM "wptests_posts"', implode( "\n", $queries ) );
	}

	public function test_wordpress_term_relationships_distinct_terms_fast_path_uses_one_native_query(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );

		$queries = array();
		$result  = $driver->query( $this->wordpress_term_relationships_distinct_terms_select_sql() );
		$this->assertSame(
			array(
				array(
					'term_id' => 1,
				),
				array(
					'term_id' => 2,
				),
			),
			array_map(
				function ( array $row ): array {
					return array( 'term_id' => (int) $row['term_id'] );
				},
				$result->fetchAll( PDO::FETCH_ASSOC )
			)
		);
		$this->assertSame( 'term_id', $result->getColumnMeta( 0 )['name'] );
		$this->assert_wordpress_term_relationships_distinct_terms_select_used_one_native_query( $queries );
		$this->assertSame(
			array(
				array(
					'found_rows' => 2,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( "UPDATE wptests_term_taxonomy SET taxonomy = 'WPTESTS_TAX' WHERE term_taxonomy_id IN (101, 102)" );
		$queries = array();
		$result  = $driver->query( $this->wordpress_term_relationships_distinct_terms_select_sql() );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'term_id' ) ) );
		$this->assert_wordpress_term_relationships_distinct_terms_select_used_one_native_query( $queries );
	}

	public function test_wordpress_term_relationships_distinct_terms_fast_path_respects_temporary_shadow_tables(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );
		$this->create_wordpress_taxonomy_group_by_temporary_shadow_tables( $driver );

		$queries = array();
		$result  = $driver->query( $this->wordpress_term_relationships_distinct_terms_select_sql() );
		$this->assertSame(
			array(
				array(
					'term_id' => 10,
				),
				array(
					'term_id' => 20,
				),
			),
			array_map(
				function ( array $row ): array {
					return array( 'term_id' => (int) $row['term_id'] );
				},
				$result->fetchAll( PDO::FETCH_ASSOC )
			)
		);
		$this->assert_wordpress_term_relationships_distinct_terms_select_used_one_native_query( $queries );

		$driver->query( 'DROP TEMPORARY TABLE wptests_term_relationships' );
		$driver->query( 'DROP TEMPORARY TABLE wptests_term_taxonomy' );
		$driver->query( 'DROP TEMPORARY TABLE wptests_terms' );
		$result = $driver->query( $this->wordpress_term_relationships_distinct_terms_select_sql() );
		$this->assertSame( 1, (int) $result->fetchAll( PDO::FETCH_ASSOC )[0]['term_id'] );
	}

	public function test_wordpress_term_relationships_distinct_terms_fast_path_supports_backtick_identifiers(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );

		$queries = array();
		$result  = $driver->query(
			"SELECT DISTINCT `t`.`term_id`
			FROM `wptests_terms` AS `t`
				INNER JOIN `wptests_term_taxonomy` AS `tt` ON `t`.`term_id` = `tt`.`term_id`
				INNER JOIN `wptests_term_relationships` AS `tr` ON `tr`.`term_taxonomy_id` = `tt`.`term_taxonomy_id`
			WHERE `tt`.`taxonomy` IN ('wptests_tax')
				AND `tr`.`object_id` IN (201, 202)
			ORDER BY `t`.`name` ASC"
		);
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'term_id' ) ) );
		$this->assert_wordpress_term_relationships_distinct_terms_select_used_one_native_query( $queries );
	}

	public function test_wordpress_term_relationships_distinct_terms_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_taxonomy_group_by_tables( $driver );

		$queries = array();
		$result  = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS DISTINCT t.term_id
			FROM wptests_terms AS t
				INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND tr.object_id IN (201, 202)
			ORDER BY t.name ASC"
		);
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'term_id' ) ) );
		$this->assertStringContainsString( '__wp_duckdb_found_rows', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT DISTINCT t.term_id
			FROM wptests_terms AS t
				INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND tr.object_id IN (201, 202)
			ORDER BY t.term_id ASC"
		);
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'term_id' ) ) );
		$this->assertStringNotContainsString( 'SELECT DISTINCT "t"."term_id" FROM "wptests_terms" AS "t"', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT DISTINCT t.term_id
			FROM wptests_terms AS t
				LEFT JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND tr.object_id IN (201, 202)
			ORDER BY t.name ASC"
		);
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'term_id' ) ) );
		$this->assertStringNotContainsString( 'SELECT DISTINCT "t"."term_id" FROM "wptests_terms" AS "t"', implode( "\n", $queries ) );
	}

	public function test_wordpress_usermeta_cache_load_fast_path_uses_one_native_query_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-usermeta-cache-fastpath-' );
		if ( false === $path ) {
			$this->fail( 'Failed to allocate a temporary DuckDB path.' );
		}
		unlink( $path );

		try {
			$setup_driver = new WP_DuckDB_Driver(
				array(
					'path'     => $path,
					'database' => 'wp',
				)
			);
			$this->create_wordpress_usermeta_cache_load_fixture( $setup_driver );
			unset( $setup_driver );
			gc_collect_cycles();

			$queries = array();
			$driver  = $this->query_logged_duckdb_driver( $queries, $path );

			$queries = array();
			$result  = $driver->query( $this->wordpress_usermeta_cache_load_select_sql() );
			$this->assertSame(
				array(
					array(
						'user_id'    => 1,
						'meta_key'   => 'first_name',
						'meta_value' => 'Alice',
					),
					array(
						'user_id'    => 1,
						'meta_key'   => 'last_name',
						'meta_value' => 'Adams',
					),
				),
				$result->fetchAll( PDO::FETCH_ASSOC )
			);
			$this->assert_wordpress_usermeta_cache_load_select_used_one_native_query( $queries, '1' );
			$this->assert_wordpress_usermeta_cache_load_metadata( $result );

			$this->assertSame(
				array(
					array(
						'found_rows' => 2,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);

			$queries = array();
			$result  = $driver->query( $this->wordpress_usermeta_cache_load_select_sql( '999' ) );
			$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ) );
			$this->assert_wordpress_usermeta_cache_load_select_used_one_native_query( $queries, '999' );
			$this->assertSame(
				array(
					array(
						'found_rows' => 0,
					),
				),
				$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
			);
		} finally {
			unset( $driver );
			@unlink( $path );
			@unlink( $path . '.wal' );
		}
	}

	public function test_wordpress_usermeta_cache_load_fast_path_handles_multiple_user_ids_and_orders_by_umeta_id(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_usermeta_cache_load_fixture( $driver );

		$queries = array();
		$result  = $driver->query( $this->wordpress_usermeta_cache_load_select_sql( '2, 1' ) );
		$this->assertSame(
			array(
				array(
					'user_id'    => 1,
					'meta_key'   => 'first_name',
					'meta_value' => 'Alice',
				),
				array(
					'user_id'    => 2,
					'meta_key'   => 'first_name',
					'meta_value' => 'Bob',
				),
				array(
					'user_id'    => 1,
					'meta_key'   => 'last_name',
					'meta_value' => 'Adams',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_usermeta_cache_load_select_used_one_native_query( $queries, '2, 1' );

		$this->assertSame(
			array(
				array(
					'found_rows' => 3,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_usermeta_cache_load_fast_path_supports_backtick_identifiers(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_usermeta_cache_load_fixture( $driver );

		$queries = array();
		$result  = $driver->query(
			'SELECT `user_id`, `meta_key`, `meta_value` FROM `wptests_usermeta` WHERE `user_id` IN (1) ORDER BY `umeta_id` ASC'
		);
		$this->assertSame(
			array(
				array(
					'user_id'    => 1,
					'meta_key'   => 'first_name',
					'meta_value' => 'Alice',
				),
				array(
					'user_id'    => 1,
					'meta_key'   => 'last_name',
					'meta_value' => 'Adams',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_usermeta_cache_load_select_used_one_native_query( $queries, '1' );
	}

	public function test_wordpress_usermeta_cache_load_fast_path_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_usermeta_cache_load_fixture( $driver );

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_usermeta (
				umeta_id BIGINT(20) UNSIGNED NOT NULL,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT \'0\',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query(
			"INSERT INTO wptests_usermeta (umeta_id, user_id, meta_key, meta_value)
			VALUES (10, 1, 'temporary_key', 'temporary_value')"
		);

		$queries = array();
		$result  = $driver->query( $this->wordpress_usermeta_cache_load_select_sql() );
		$this->assertSame(
			array(
				array(
					'user_id'    => 1,
					'meta_key'   => 'temporary_key',
					'meta_value' => 'temporary_value',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_usermeta_cache_load_select_used_one_native_query( $queries, '1' );

		$this->assertSame(
			array(
				array(
					'found_rows' => 1,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_usermeta' );
		$result = $driver->query( $this->wordpress_usermeta_cache_load_select_sql() );
		$this->assertSame( 'first_name', $result->fetchAll( PDO::FETCH_ASSOC )[0]['meta_key'] );
	}

	public function test_wordpress_usermeta_cache_load_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_usermeta_cache_load_fixture( $driver );

		$queries = array();
		$result  = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS user_id, meta_key, meta_value FROM wptests_usermeta WHERE user_id IN (1) ORDER BY umeta_id ASC'
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertGreaterThan( 1, count( $queries ), implode( "\n", $queries ) );
		$this->assertTrue(
			count(
				array_filter(
					$queries,
					function ( string $query ): bool {
						return false !== strpos( $query, '__wp_duckdb_found_rows' );
					}
				)
			) > 0,
			implode( "\n", $queries )
		);

		$queries = array();
		$result  = $driver->query(
			'SELECT user_id, meta_key, meta_value FROM wptests_usermeta AS um WHERE user_id IN (1) ORDER BY umeta_id ASC'
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( ' AS ', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT user_id AS uid, meta_key, meta_value FROM wptests_usermeta WHERE user_id IN (1) ORDER BY umeta_id ASC'
		);
		$this->assertSame( 'uid', $result->getColumnMeta( 0 )['name'] );
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( 'user_id AS uid', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT user_id, meta_key, meta_value FROM wptests_usermeta WHERE user_id IN (1) AND meta_key = 'first_name' ORDER BY umeta_id ASC"
		);
		$this->assertSame(
			array(
				array(
					'user_id'    => 1,
					'meta_key'   => 'first_name',
					'meta_value' => 'Alice',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( 'meta_key', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT user_id, meta_key, meta_value FROM wptests_usermeta WHERE user_id IN (1) ORDER BY umeta_id DESC'
		);
		$this->assertSame( 'last_name', $result->fetchAll( PDO::FETCH_ASSOC )[0]['meta_key'] );
		$this->assertStringContainsString( 'DESC', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT user_id, meta_key, meta_value FROM wptests_usermeta WHERE user_id IN ('1') ORDER BY umeta_id ASC"
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( "'1'", implode( "\n", $queries ) );
	}

	public function test_wordpress_non_user_meta_cache_load_fast_path_uses_one_native_query_on_fresh_driver(): void {
		$this->requireDuckDBRuntime();

		foreach ( $this->wordpress_non_user_meta_cache_load_cases() as $case ) {
			$path = tempnam( sys_get_temp_dir(), 'wp-duckdb-meta-cache-fastpath-' );
			if ( false === $path ) {
				$this->fail( 'Failed to allocate a temporary DuckDB path.' );
			}
			unlink( $path );

			try {
				$setup_driver = new WP_DuckDB_Driver(
					array(
						'path'     => $path,
						'database' => 'wp',
					)
				);
				$this->create_wordpress_meta_cache_load_fixture( $setup_driver, $case['table'], $case['object_column'] );
				unset( $setup_driver );
				gc_collect_cycles();

				$queries = array();
				$driver  = $this->query_logged_duckdb_driver( $queries, $path );

				$queries = array();
				$result  = $driver->query(
					$this->wordpress_meta_cache_load_select_sql(
						$case['object_column'],
						'2, 1',
						$case['table'],
						$case['order_column']
					)
				);
				$this->assertSame(
					$this->wordpress_meta_cache_load_expected_rows( $case['table'], $case['object_column'] ),
					$result->fetchAll( PDO::FETCH_ASSOC ),
					$case['table']
				);
				$this->assert_wordpress_meta_cache_load_select_used_one_native_query(
					$queries,
					$case['table'],
					$case['object_column'],
					$case['order_column'],
					'2, 1'
				);
				$this->assert_wordpress_meta_cache_load_metadata( $result, $case['table'], $case['object_column'] );

				$this->assertSame(
					array(
						array(
							'found_rows' => 3,
						),
					),
					$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC ),
					$case['table']
				);

				$queries = array();
				$result  = $driver->query(
					$this->wordpress_meta_cache_load_select_sql(
						$case['object_column'],
						'999',
						$case['table'],
						$case['order_column']
					)
				);
				$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ), $case['table'] );
				$this->assert_wordpress_meta_cache_load_select_used_one_native_query(
					$queries,
					$case['table'],
					$case['object_column'],
					$case['order_column'],
					'999'
				);
				$this->assertSame(
					array(
						array(
							'found_rows' => 0,
						),
					),
					$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC ),
					$case['table']
				);
			} finally {
				unset( $driver );
				@unlink( $path );
				@unlink( $path . '.wal' );
			}
		}
	}

	public function test_wordpress_non_user_meta_cache_load_fast_path_supports_backticks_and_temp_shadow(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_meta_cache_load_fixture( $driver, 'wptests_postmeta', 'post_id' );

		$queries = array();
		$result  = $driver->query(
			'SELECT `post_id`, `meta_key`, `meta_value` FROM `wptests_postmeta` WHERE `post_id` IN (1) ORDER BY `meta_id` ASC'
		);
		$this->assertSame(
			array(
				array(
					'post_id'    => 1,
					'meta_key'   => 'first_name',
					'meta_value' => 'wptests_postmeta-Alice',
				),
				array(
					'post_id'    => 1,
					'meta_key'   => 'last_name',
					'meta_value' => 'wptests_postmeta-Adams',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_meta_cache_load_select_used_one_native_query(
			$queries,
			'wptests_postmeta',
			'post_id',
			'meta_id',
			'1'
		);

		$driver->query(
			'CREATE TEMPORARY TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT \'0\',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key, meta_value)
			VALUES (10, 1, 'temporary_key', 'temporary_value')"
		);

		$queries = array();
		$result  = $driver->query(
			$this->wordpress_meta_cache_load_select_sql( 'post_id', '1', 'wptests_postmeta', 'meta_id' )
		);
		$this->assertSame(
			array(
				array(
					'post_id'    => 1,
					'meta_key'   => 'temporary_key',
					'meta_value' => 'temporary_value',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_wordpress_meta_cache_load_select_used_one_native_query(
			$queries,
			'wptests_postmeta',
			'post_id',
			'meta_id',
			'1'
		);

		$this->assertSame(
			array(
				array(
					'found_rows' => 1,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_postmeta' );
		$result = $driver->query(
			$this->wordpress_meta_cache_load_select_sql( 'post_id', '1', 'wptests_postmeta', 'meta_id' )
		);
		$this->assertSame( 'first_name', $result->fetchAll( PDO::FETCH_ASSOC )[0]['meta_key'] );
	}

	public function test_wordpress_non_user_meta_cache_load_fast_path_does_not_capture_unsupported_shapes(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_meta_cache_load_fixture( $driver, 'wptests_postmeta', 'post_id' );
		$this->create_wordpress_meta_cache_load_fixture( $driver, 'wptests_custommeta', 'post_id' );

		$queries = array();
		$result  = $driver->query(
			'SELECT SQL_CALC_FOUND_ROWS post_id, meta_key, meta_value FROM wptests_postmeta WHERE post_id IN (1) ORDER BY meta_id ASC'
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertGreaterThan( 1, count( $queries ), implode( "\n", $queries ) );
		$this->assertStringContainsString( '__wp_duckdb_found_rows', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT post_id, meta_key, meta_value FROM wptests_postmeta AS pm WHERE post_id IN (1) ORDER BY meta_id ASC'
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( ' AS ', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT post_id AS pid, meta_key, meta_value FROM wptests_postmeta WHERE post_id IN (1) ORDER BY meta_id ASC'
		);
		$this->assertSame( 'pid', $result->getColumnMeta( 0 )['name'] );
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( 'post_id AS pid', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT post_id, meta_key, meta_value FROM wptests_postmeta WHERE post_id IN (1) AND meta_key = 'first_name' ORDER BY meta_id ASC"
		);
		$this->assertSame(
			array(
				array(
					'post_id'    => 1,
					'meta_key'   => 'first_name',
					'meta_value' => 'wptests_postmeta-Alice',
				),
			),
			$result->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString( 'meta_key', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT post_id, meta_key, meta_value FROM wptests_postmeta WHERE post_id IN (1) ORDER BY meta_id DESC'
		);
		$this->assertSame( 'last_name', $result->fetchAll( PDO::FETCH_ASSOC )[0]['meta_key'] );
		$this->assertStringContainsString( 'DESC', implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			"SELECT post_id, meta_key, meta_value FROM wptests_postmeta WHERE post_id IN ('1') ORDER BY meta_id ASC"
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringContainsString( "'1'", implode( "\n", $queries ) );

		$queries = array();
		$result  = $driver->query(
			'SELECT post_id, meta_key, meta_value FROM wptests_custommeta WHERE post_id IN (1) ORDER BY meta_id ASC'
		);
		$this->assertSame( 2, count( $result->fetchAll( PDO::FETCH_ASSOC ) ) );
		$this->assertStringNotContainsString( 'SELECT "post_id", "meta_key", "meta_value" FROM "wptests_custommeta"', implode( "\n", $queries ) );
	}

	public function test_wordpress_options_autoload_fast_path_does_not_capture_sql_calc_found_rows(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$queries = array();
		$result  = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS option_name, option_value FROM wptests_options WHERE autoload IN ('yes', 'on', 'auto-on', 'auto') LIMIT 2"
		);

		$this->assertCount( 2, $result->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertGreaterThan( 1, count( $queries ), implode( "\n", $queries ) );
		$this->assertTrue(
			count(
				array_filter(
					$queries,
					function ( string $query ): bool {
						return false !== strpos( $query, '__wp_duckdb_found_rows' );
					}
				)
			) > 0,
			implode( "\n", $queries )
		);

		$this->assertSame(
			array(
				array(
					'found_rows' => 3,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_options_autoload_fast_path_does_not_capture_different_literal_case(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_options (
				option_name VARCHAR(191) NOT NULL,
				option_value VARCHAR(191) NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload) VALUES
			('lowercase_autoload', 'Lowercase Autoload', 'yes'),
			('uppercase_autoload', 'Uppercase Autoload', 'YES')"
		);

		$queries = array();
		$result  = $driver->query(
			"SELECT option_name, option_value FROM wptests_options WHERE autoload IN ('YES', 'ON', 'AUTO-ON', 'AUTO')"
		);

		$this->assertSame(
			array(
				array(
					'option_name'  => 'lowercase_autoload',
					'option_value' => 'Lowercase Autoload',
				),
				array(
					'option_name'  => 'uppercase_autoload',
					'option_value' => 'Uppercase Autoload',
				),
			),
			$this->sorted_wordpress_options_autoload_rows( $result )
		);
		$this->assertSame( 1, count( $queries ), implode( "\n", $queries ) );
		$this->assertStringContainsString( "'YES'", $queries[0] );
		$this->assertStringNotContainsString( "\"autoload\" IN ('yes', 'on', 'auto-on', 'auto')", $queries[0] );
		$this->assertSame(
			array(
				array(
					'found_rows' => 2,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found_rows' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wordpress_options_autoload_select_respects_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$queries = array();
		$driver  = $this->query_logged_duckdb_driver( $queries );
		$this->create_wordpress_options_autoload_fixture( $driver );

		$this->assert_wordpress_options_autoload_rows( $driver );
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_options (
				option_name VARCHAR(191) NOT NULL,
				option_value VARCHAR(191) NOT NULL,
				autoload VARCHAR(20) NOT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('temporary_name', 'temporary_value', 'yes')"
		);

		$this->assertSame(
			array(
				array(
					'option_name'  => 'temporary_name',
					'option_value' => 'temporary_value',
				),
			),
			$this->sorted_wordpress_options_autoload_rows( $driver->query( $this->wordpress_options_autoload_select_sql() ) )
		);

		$driver->query( 'DROP TEMPORARY TABLE wptests_options' );
		$this->assert_wordpress_options_autoload_rows( $driver );
	}

	public function test_create_table_metadata_inserts_are_batched(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE batched_metadata (
				id INT NOT NULL,
				slug VARCHAR(20) NOT NULL,
				name VARCHAR(20),
				KEY slug_name_key (slug, name)
			)'
		);

		$queries        = $driver->get_last_duckdb_queries();
		$column_inserts = array_values(
			array_filter(
				$queries,
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'INSERT INTO "__wp_duckdb_column_metadata"' );
				}
			)
		);
		$index_inserts  = array_values(
			array_filter(
				$queries,
				function ( string $sql ): bool {
					return 0 === strpos( $sql, 'INSERT INTO "__wp_duckdb_index_metadata"' );
				}
			)
		);

		$this->assertCount( 1, $column_inserts );
		$this->assertCount( 1, $index_inserts );
		$this->assertStringContainsString(
			"), ('batched_metadata', 2, 'slug'",
			$column_inserts[0]
		);
		$this->assertStringContainsString(
			"), ('batched_metadata', 'slug_name_key', 1, 2, 'name'",
			$index_inserts[0]
		);

		$this->assertSame(
			array( 'id', 'slug', 'name' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM batched_metadata' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$index_rows = $driver->query( 'SHOW INDEX FROM batched_metadata' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( array( 'slug', 'name' ), array_column( $index_rows, 'Column_name' ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $index_rows, 'Seq_in_index' ) ) );
	}

	public function test_temporary_table_inline_checks_use_temp_metadata_only(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TEMPORARY TABLE temp_inline_checks (
				id INT CHECK (id >= 0),
				amount INT CHECK (amount > 0)
			)'
		);

		$this->assertSame( 1, $driver->query( 'INSERT INTO temp_inline_checks (id, amount) VALUES (1, 10)' )->rowCount() );

		try {
			$driver->query( 'UPDATE temp_inline_checks SET amount = -1 WHERE id = 1' );
			$this->fail( 'Expected temporary inline CHECK constraint enforcement to reject an invalid UPDATE.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$create_rows = $driver->query( 'SHOW CREATE TABLE temp_inline_checks' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TEMPORARY TABLE `temp_inline_checks` (',
					'  `id` int DEFAULT NULL,',
					'  `amount` int DEFAULT NULL,',
					'  CONSTRAINT `temp_inline_checks_chk_1` CHECK (id >= 0),',
					'  CONSTRAINT `temp_inline_checks_chk_2` CHECK (amount > 0)',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
				)
			),
			$create_rows[0]['Create Table']
		);

		$this->assertSame( array(), $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
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
			array_column( $driver->query( 'SHOW COLUMNS FROM wp.t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'b' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM information_schema.t FROM wp' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'b' ),
			array_column( $driver->query( 'DESCRIBE t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'b' ),
			array_column( $driver->query( 'DESCRIBE wp.t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
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
		$this->assertSame(
			array(
				array(
					'Tables_in_wp' => 't',
					'Table_type'   => 'BASE TABLE',
				),
			),
			$driver->query( "SHOW FULL TABLES LIKE 't'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TABLE t' );
		$this->assertSame(
			array( 'a' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( 'a' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM wp.t' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array( array( 'a' => 1 ) ),
			$driver->query( 'SELECT * FROM t' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_targeted_table_resolution_preserves_temp_shadow_without_broad_table_scan(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE runtime_lookup_target (persistent_id INT, persistent_label VARCHAR(20))' );
		$driver->query( "INSERT INTO runtime_lookup_target VALUES (1, 'persistent')" );

		for ( $i = 0; $i < 5; ++$i ) {
			$driver->query( 'CREATE TEMPORARY TABLE runtime_lookup_noise_' . $i . ' (noise_id INT)' );
		}

		$driver->query( 'CREATE TEMPORARY TABLE runtime_lookup_target (temp_id INT, temp_label VARCHAR(20))' );
		$driver->query( "INSERT INTO runtime_lookup_target VALUES (2, 'temporary')" );

		$this->assertSame(
			array(
				array(
					'temp_id'    => 2,
					'temp_label' => 'temporary',
				),
			),
			$driver->query( 'SELECT * FROM runtime_lookup_target' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'ALTER TABLE runtime_lookup_target ADD COLUMN temp_marker INT' );
		foreach ( $driver->get_last_duckdb_queries() as $duckdb_sql ) {
			$this->assertFalse(
				0 === strpos( $duckdb_sql, 'SELECT table_name FROM information_schema.tables' )
				&& false !== strpos( $duckdb_sql, 'ORDER BY table_name' ),
				$duckdb_sql
			);
		}

		$this->assertSame(
			array( 'temp_id', 'temp_label', 'temp_marker' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM runtime_lookup_target' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);

		$driver->query( 'DROP TEMPORARY TABLE runtime_lookup_target' );
		foreach ( $driver->get_last_duckdb_queries() as $duckdb_sql ) {
			$this->assertFalse(
				0 === strpos( $duckdb_sql, 'SELECT table_name FROM information_schema.tables' )
				&& false !== strpos( $duckdb_sql, 'ORDER BY table_name' ),
				$duckdb_sql
			);
		}

		$this->assertSame(
			array(
				array(
					'persistent_id'    => 1,
					'persistent_label' => 'persistent',
				),
			),
			$driver->query( 'SELECT * FROM runtime_lookup_target' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( 'persistent_id', 'persistent_label' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM runtime_lookup_target' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
	}

	public function test_qualified_describe_does_not_expose_information_schema_tables(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE columns (user_col INT)' );

		$this->assertSame(
			array(),
			$driver->query( 'DESCRIBE information_schema.columns' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'SHOW COLUMNS FROM information_schema.columns' );
			$this->fail( 'Expected information_schema SHOW COLUMNS to be rejected.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Table 'information_schema.columns' doesn't exist", $e->getMessage() );
		}

		$this->assertSame(
			array( 'user_col' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM columns' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
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

	public function test_alter_table_options_and_key_maintenance_are_noops(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE alter_options_noop (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(20)
			) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Original comment'"
		);
		$driver->query( "INSERT INTO alter_options_noop (name) VALUES ('first'), ('second')" );

		$before = $this->alter_table_auto_increment_snapshot( $driver, 'alter_options_noop' );
		$this->assertSame( array( array( 'AUTO_INCREMENT' => 3 ) ), $before['auto_increment'] );

		foreach (
			array(
				'ALTER TABLE alter_options_noop ENGINE=InnoDB',
				'ALTER TABLE alter_options_noop DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"ALTER TABLE alter_options_noop COMMENT = 'Ignored comment'",
				'ALTER TABLE alter_options_noop ROW_FORMAT=DYNAMIC',
				'ALTER TABLE alter_options_noop DISABLE KEYS',
				'ALTER TABLE alter_options_noop ENABLE KEYS',
			) as $sql
		) {
			$this->assertSame( 0, $driver->query( $sql )->rowCount(), 'Unexpected row count for SQL: ' . $sql );
			$this->assertSame( $before, $this->alter_table_auto_increment_snapshot( $driver, 'alter_options_noop' ), 'No-op ALTER TABLE mutated state for SQL: ' . $sql );
		}
	}

	public function test_mixed_alter_table_auto_increment_rejections_do_not_mutate(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_ai_guard (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(20)
			)'
		);
		$driver->query( "INSERT INTO alter_ai_guard (name) VALUES ('first')" );

		$before = $this->alter_table_auto_increment_snapshot( $driver, 'alter_ai_guard' );
		foreach (
			array(
				'ALTER TABLE alter_ai_guard AUTO_INCREMENT = 50, ALGORITHM=INPLACE' => 'Only ADD, DROP, CHANGE, MODIFY, AUTO_INCREMENT, table option, and ENABLE/DISABLE KEYS actions are supported',
				'ALTER TABLE alter_ai_guard AUTO_INCREMENT = 50, ADD COLUMN generated_id BIGINT AUTO_INCREMENT' => 'ADD COLUMN AUTO_INCREMENT is not supported',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected mixed ALTER TABLE AUTO_INCREMENT rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_auto_increment_snapshot( $driver, 'alter_ai_guard' ),
				'Mixed ALTER TABLE AUTO_INCREMENT rejection mutated state for SQL: ' . $sql
			);
		}
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

	public function test_alter_table_drop_primary_key_column_rebuilds_and_cleans_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_pk_rebuild (
				id INT NOT NULL,
				code VARCHAR(20) NOT NULL,
				amount INT,
				note VARCHAR(20),
				PRIMARY KEY (id),
				UNIQUE KEY code_unique (code),
				KEY amount_idx (amount),
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( "INSERT INTO drop_col_pk_rebuild (id, code, amount, note) VALUES (1, 'a', 10, 'first'), (2, 'b', 20, 'second')" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE drop_col_pk_rebuild DROP COLUMN id' )->rowCount() );
		$driver->query( "INSERT INTO drop_col_pk_rebuild (code, amount, note) VALUES ('c', 30, 'third')" );

		try {
			$driver->query( "INSERT INTO drop_col_pk_rebuild (code, amount, note) VALUES ('a', 40, 'duplicate')" );
			$this->fail( 'Expected surviving UNIQUE index to remain enforced after DROP COLUMN rebuild.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		try {
			$driver->query( "INSERT INTO drop_col_pk_rebuild (code, amount, note) VALUES ('d', 0, 'invalid')" );
			$this->fail( 'Expected surviving CHECK constraint to remain enforced after DROP COLUMN rebuild.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'code'   => 'a',
					'amount' => 10,
					'note'   => 'first',
				),
				array(
					'code'   => 'b',
					'amount' => 20,
					'note'   => 'second',
				),
				array(
					'code'   => 'c',
					'amount' => 30,
					'note'   => 'third',
				),
			),
			$driver->query( 'SELECT code, amount, note FROM drop_col_pk_rebuild ORDER BY code' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				'code'   => 'UNI',
				'amount' => 'MUL',
				'note'   => '',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_col_pk_rebuild' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME'      => 'code',
					'ORDINAL_POSITION' => 1,
					'EXTRA'            => '',
				),
				array(
					'COLUMN_NAME'      => 'amount',
					'ORDINAL_POSITION' => 2,
					'EXTRA'            => '',
				),
				array(
					'COLUMN_NAME'      => 'note',
					'ORDINAL_POSITION' => 3,
					'EXTRA'            => '',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, ORDINAL_POSITION, EXTRA
				FROM information_schema.columns
				WHERE table_schema = 'wp' AND table_name = 'drop_col_pk_rebuild'
				ORDER BY ORDINAL_POSITION"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE drop_col_pk_rebuild' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringNotContainsString( '`id`', $create_sql );
		$this->assertStringNotContainsString( 'PRIMARY KEY', $create_sql );
		$this->assertStringContainsString( 'UNIQUE KEY `code_unique` (`code`)', $create_sql );
		$this->assertStringContainsString( 'KEY `amount_idx` (`amount`)', $create_sql );
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $create_sql );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT index_name
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'drop_col_pk_rebuild'
					AND index_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT constraint_name
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name = 'drop_col_pk_rebuild'
					AND constraint_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT constraint_name
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name = 'drop_col_pk_rebuild'
					AND constraint_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_drop_composite_primary_key_member_shrinks_keys(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_composite_pk (
				site_id INT NOT NULL,
				option_id INT NOT NULL,
				name VARCHAR(20),
				payload INT,
				PRIMARY KEY (site_id, option_id),
				KEY site_name (site_id, name),
				KEY only_site (site_id),
				KEY payload_idx (payload)
			)'
		);
		$driver->query( "INSERT INTO drop_col_composite_pk (site_id, option_id, name, payload) VALUES (1, 10, 'a', 100), (2, 20, 'b', 200)" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE drop_col_composite_pk DROP site_id' )->rowCount() );

		$index_columns = array();
		foreach ( $driver->query( 'SHOW INDEX FROM drop_col_composite_pk' )->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$index_columns[ $row['Key_name'] ][] = $row['Column_name'];
		}
		$this->assertSame( array( 'option_id' ), $index_columns['PRIMARY'] );
		$this->assertSame( array( 'name' ), $index_columns['site_name'] );
		$this->assertSame( array( 'payload' ), $index_columns['payload_idx'] );
		$this->assertArrayNotHasKey( 'only_site', $index_columns );
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT index_name
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'drop_col_composite_pk'
					AND index_name = 'only_site'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( "INSERT INTO drop_col_composite_pk (option_id, name, payload) VALUES (10, 'duplicate', 300)" );
			$this->fail( 'Expected shrunken PRIMARY KEY to remain enforced.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'option_id' => 10,
					'name'      => 'a',
					'payload'   => 100,
				),
				array(
					'option_id' => 20,
					'name'      => 'b',
					'payload'   => 200,
				),
			),
			$driver->query( 'SELECT option_id, name, payload FROM drop_col_composite_pk ORDER BY option_id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE drop_col_composite_pk' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringContainsString( 'PRIMARY KEY (`option_id`)', $create_sql );
		$this->assertStringContainsString( 'KEY `site_name` (`name`)', $create_sql );
		$this->assertStringNotContainsString( 'KEY `only_site`', $create_sql );
	}

	public function test_alter_table_drop_auto_increment_primary_key_column_removes_sequence_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_ai_rebuild (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(20) NOT NULL,
				payload INT,
				PRIMARY KEY (id),
				UNIQUE KEY slug_unique (slug),
				KEY payload_idx (payload)
			) AUTO_INCREMENT=50'
		);
		$driver->query( "INSERT INTO drop_col_ai_rebuild (slug, payload) VALUES ('a', 100), ('b', 200)" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE drop_col_ai_rebuild DROP COLUMN id' )->rowCount() );
		$this->assertNotEmpty(
			array_filter(
				$driver->get_last_duckdb_queries(),
				function ( string $sql ): bool {
					return false !== strpos( $sql, 'DROP SEQUENCE IF EXISTS' );
				}
			)
		);
		$driver->query( "INSERT INTO drop_col_ai_rebuild (slug, payload) VALUES ('c', 300)" );

		$this->assertSame(
			array(
				array(
					'slug'    => 'a',
					'payload' => 100,
				),
				array(
					'slug'    => 'b',
					'payload' => 200,
				),
				array(
					'slug'    => 'c',
					'payload' => 300,
				),
			),
			$driver->query( 'SELECT slug, payload FROM drop_col_ai_rebuild ORDER BY slug' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertNull(
			$driver->query(
				"SELECT `AUTO_INCREMENT`
				FROM information_schema.tables
				WHERE table_schema = 'wp' AND table_name = 'drop_col_ai_rebuild'"
			)->fetch( PDO::FETCH_ASSOC )['AUTO_INCREMENT']
		);
		$this->assertSame(
			array(
				array(
					'COLUMN_NAME' => 'slug',
					'EXTRA'       => '',
				),
				array(
					'COLUMN_NAME' => 'payload',
					'EXTRA'       => '',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, EXTRA
				FROM information_schema.columns
				WHERE table_schema = 'wp' AND table_name = 'drop_col_ai_rebuild'
				ORDER BY ORDINAL_POSITION"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE drop_col_ai_rebuild' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringNotContainsString( '`id`', $create_sql );
		$this->assertStringNotContainsString( 'AUTO_INCREMENT', $create_sql );
		$this->assertStringNotContainsString( 'PRIMARY KEY', $create_sql );
		$this->assertStringContainsString( 'UNIQUE KEY `slug_unique` (`slug`)', $create_sql );
		$this->assertStringContainsString( 'KEY `payload_idx` (`payload`)', $create_sql );
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

	public function test_alter_table_drop_primary_key_column_rebuild_targets_temporary_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE shadow_drop_pk (id INT NOT NULL, keep_col VARCHAR(20), PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO shadow_drop_pk (id, keep_col) VALUES (1, 'persistent')" );
		$driver->query( 'CREATE TEMPORARY TABLE shadow_drop_pk (id INT NOT NULL, keep_col VARCHAR(20), temp_col VARCHAR(20), PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO shadow_drop_pk (id, keep_col, temp_col) VALUES (10, 'temporary', 'temp')" );

		$driver->query( 'ALTER TABLE shadow_drop_pk DROP COLUMN id' );
		$this->assertSame(
			array( 'keep_col', 'temp_col' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_drop_pk' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'keep_col' => 'temporary',
					'temp_col' => 'temp',
				),
			),
			$driver->query( 'SELECT keep_col, temp_col FROM shadow_drop_pk' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$driver->query( 'DROP TEMPORARY TABLE shadow_drop_pk' );
		$this->assertSame(
			array( 'id', 'keep_col' ),
			array_column( $driver->query( 'SHOW COLUMNS FROM shadow_drop_pk' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' )
		);
		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'keep_col' => 'persistent',
				),
			),
			$driver->query( 'SELECT id, keep_col FROM shadow_drop_pk' )->fetchAll( PDO::FETCH_ASSOC )
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

	public function test_alter_table_change_modify_rebuilds_same_name_key_columns(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			"CREATE TABLE change_key_rebuild (
				id VARCHAR(20) NOT NULL,
				code VARCHAR(20) DEFAULT '7',
				payload VARCHAR(20),
				PRIMARY KEY (id),
				UNIQUE KEY code_unique (code)
			)"
		);
		$driver->query( "INSERT INTO change_key_rebuild (id, code, payload) VALUES ('1', '10', 'alpha'), ('2', '11', 'bravo')" );

		$driver->query( 'ALTER TABLE change_key_rebuild CHANGE COLUMN id id INT NOT NULL' );
		$driver->query( "ALTER TABLE change_key_rebuild MODIFY COLUMN code SMALLINT NOT NULL DEFAULT 42 COMMENT 'Numeric code'" );
		$driver->query( "INSERT INTO change_key_rebuild (id, payload) VALUES (3, 'charlie')" );

		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'code'    => 10,
					'payload' => 'alpha',
				),
				array(
					'id'      => 2,
					'code'    => 11,
					'payload' => 'bravo',
				),
				array(
					'id'      => 3,
					'code'    => 42,
					'payload' => 'charlie',
				),
			),
			$driver->query( 'SELECT id, code, payload FROM change_key_rebuild ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$columns = array_column( $driver->query( 'SHOW FULL COLUMNS FROM change_key_rebuild' )->fetchAll( PDO::FETCH_ASSOC ), null, 'Field' );
		$this->assertSame( 'int', $columns['id']['Type'] );
		$this->assertSame( 'NO', $columns['id']['Null'] );
		$this->assertSame( 'PRI', $columns['id']['Key'] );
		$this->assertSame( 'smallint', $columns['code']['Type'] );
		$this->assertSame( 'NO', $columns['code']['Null'] );
		$this->assertSame( '42', $columns['code']['Default'] );
		$this->assertSame( 'UNI', $columns['code']['Key'] );
		$this->assertSame( 'Numeric code', $columns['code']['Comment'] );

		$index_rows = array_map(
			function ( array $row ): array {
				return array(
					'Key_name'     => $row['Key_name'],
					'Seq_in_index' => (int) $row['Seq_in_index'],
					'Column_name'  => $row['Column_name'],
					'Non_unique'   => (int) $row['Non_unique'],
				);
			},
			$driver->query( 'SHOW INDEX FROM change_key_rebuild' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Key_name'     => 'PRIMARY',
					'Seq_in_index' => 1,
					'Column_name'  => 'id',
					'Non_unique'   => 0,
				),
				array(
					'Key_name'     => 'code_unique',
					'Seq_in_index' => 1,
					'Column_name'  => 'code',
					'Non_unique'   => 0,
				),
			),
			$index_rows
		);

		$statistics_rows = array_map(
			function ( array $row ): array {
				return array(
					'index_name'  => $row['index_name'] ?? $row['INDEX_NAME'],
					'column_name' => $row['column_name'] ?? $row['COLUMN_NAME'],
					'non_unique'  => (int) ( $row['non_unique'] ?? $row['NON_UNIQUE'] ),
					'nullable'    => $row['nullable'] ?? $row['NULLABLE'],
				);
			},
			$driver->query(
				"SELECT index_name AS index_name, column_name AS column_name, non_unique AS non_unique, nullable AS nullable
				FROM information_schema.statistics
				WHERE table_schema = 'wp' AND table_name = 'change_key_rebuild'
				ORDER BY CASE WHEN index_name = 'PRIMARY' THEN 0 ELSE 1 END, index_name, seq_in_index"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'index_name'  => 'PRIMARY',
					'column_name' => 'id',
					'non_unique'  => 0,
					'nullable'    => '',
				),
				array(
					'index_name'  => 'code_unique',
					'column_name' => 'code',
					'non_unique'  => 0,
					'nullable'    => '',
				),
			),
			$statistics_rows
		);

		try {
			$driver->query( "INSERT INTO change_key_rebuild (id, code, payload) VALUES (4, 10, 'duplicate')" );
			$this->fail( 'Expected rebuilt UNIQUE index to remain enforced.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_change_existing_auto_increment_column_updates_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE dbdelta_auto_increment (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(50) NOT NULL,
				payload VARCHAR(50),
				PRIMARY KEY (id),
				UNIQUE KEY slug_unique (slug)
			) AUTO_INCREMENT=5'
		);
		$driver->query( "INSERT INTO dbdelta_auto_increment (slug, payload) VALUES ('alpha', 'one'), ('bravo', 'two')" );

		$result  = $driver->query( 'ALTER TABLE dbdelta_auto_increment CHANGE COLUMN id id int(11) NOT NULL AUTO_INCREMENT' );
		$queries = implode( "\n", $driver->get_last_duckdb_queries() );
		$driver->query( "INSERT INTO dbdelta_auto_increment (slug, payload) VALUES ('charlie', 'three')" );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertStringNotContainsString( 'CREATE TEMP TABLE "__wp_duckdb_rebuild_', $queries );
		$this->assertSame(
			array(
				array(
					'id'      => 5,
					'slug'    => 'alpha',
					'payload' => 'one',
				),
				array(
					'id'      => 6,
					'slug'    => 'bravo',
					'payload' => 'two',
				),
				array(
					'id'      => 7,
					'slug'    => 'charlie',
					'payload' => 'three',
				),
			),
			$driver->query( 'SELECT id, slug, payload FROM dbdelta_auto_increment ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$columns = array_column( $driver->query( 'SHOW FULL COLUMNS FROM dbdelta_auto_increment' )->fetchAll( PDO::FETCH_ASSOC ), null, 'Field' );
		$this->assertSame( 'int(11)', $columns['id']['Type'] );
		$this->assertSame( 'NO', $columns['id']['Null'] );
		$this->assertSame( 'PRI', $columns['id']['Key'] );
		$this->assertSame( 'auto_increment', $columns['id']['Extra'] );

		$this->assertSame(
			array( array( 'AUTO_INCREMENT' => 8 ) ),
			$driver->query(
				"SELECT `AUTO_INCREMENT`
				FROM information_schema.tables
				WHERE table_schema = 'wp' AND table_name = 'dbdelta_auto_increment'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString(
			'`id` int(11) NOT NULL AUTO_INCREMENT',
			$driver->query( 'SHOW CREATE TABLE dbdelta_auto_increment' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		try {
			$driver->query( "INSERT INTO dbdelta_auto_increment (slug, payload) VALUES ('alpha', 'duplicate')" );
			$this->fail( 'Expected UNIQUE index to remain enforced after AUTO_INCREMENT CHANGE COLUMN.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_change_existing_auto_increment_noop_runs_inside_transaction(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE dbdelta_auto_increment_noop (
				id INT(11) NOT NULL AUTO_INCREMENT,
				slug VARCHAR(50) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( "INSERT INTO dbdelta_auto_increment_noop (slug) VALUES ('alpha')" );

		$driver->query( 'BEGIN' );
		$result  = $driver->query( 'ALTER TABLE dbdelta_auto_increment_noop CHANGE COLUMN `id` id int(11) NOT NULL AUTO_INCREMENT' );
		$queries = implode( "\n", $driver->get_last_duckdb_queries() );
		$this->assertTrue( $driver->get_connection()->inTransaction() );
		$driver->query( "INSERT INTO dbdelta_auto_increment_noop (slug) VALUES ('bravo')" );
		$driver->query( 'COMMIT' );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertStringNotContainsString( 'CREATE TEMP TABLE "__wp_duckdb_rebuild_', $queries );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'slug' => 'alpha',
				),
				array(
					'id'   => 2,
					'slug' => 'bravo',
				),
			),
			$driver->query( 'SELECT id, slug FROM dbdelta_auto_increment_noop ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$columns = array_column( $driver->query( 'SHOW FULL COLUMNS FROM dbdelta_auto_increment_noop' )->fetchAll( PDO::FETCH_ASSOC ), null, 'Field' );
		$this->assertSame( 'int(11)', $columns['id']['Type'] );
		$this->assertSame( 'NO', $columns['id']['Null'] );
		$this->assertSame( 'PRI', $columns['id']['Key'] );
		$this->assertSame( 'auto_increment', $columns['id']['Extra'] );
	}

	public function test_alter_table_change_modify_key_rebuild_rejects_unsafe_conversion_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE change_key_bad_cast (
				code VARCHAR(20),
				payload INT,
				KEY code_idx (code)
			)'
		);
		$driver->query( "INSERT INTO change_key_bad_cast (code, payload) VALUES ('abc', 1)" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'change_key_bad_cast' );
		try {
			$driver->query( 'ALTER TABLE change_key_bad_cast MODIFY COLUMN code INT NOT NULL' );
			$this->fail( 'Expected unsafe key-column conversion rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'existing rows cannot be converted', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'change_key_bad_cast' ) );
	}

	public function test_alter_table_change_modify_key_rebuild_rejects_transaction_and_multi_action_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE change_key_guard (id VARCHAR(20) NOT NULL, payload INT, PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO change_key_guard (id, payload) VALUES ('1', 1)" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'change_key_guard' );
		$driver->query( 'BEGIN' );
		try {
			$driver->query( 'ALTER TABLE change_key_guard CHANGE COLUMN id id INT NOT NULL' );
			$this->fail( 'Expected active transaction CHANGE/MODIFY rebuild rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHANGE/MODIFY cannot run inside an active DuckDB transaction', $e->getMessage() );
		}
		$driver->query( 'ROLLBACK' );
		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'change_key_guard' ) );

		foreach (
			array(
				'ALTER TABLE change_key_guard CHANGE COLUMN id id INT NOT NULL, ADD COLUMN should_not_exist INT',
				'ALTER TABLE change_key_guard ADD COLUMN should_not_exist INT, CHANGE COLUMN id id INT NOT NULL',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action CHANGE/MODIFY rebuild rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'CHANGE/MODIFY requiring a table rebuild cannot be combined with other ALTER TABLE actions', $e->getMessage() );
			}

			$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'change_key_guard' ) );
		}
	}

	public function test_alter_table_change_modify_rejects_protected_definitions(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE change_pk (id INT NOT NULL, note VARCHAR(20), UNIQUE KEY note_unique (note), PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO change_pk (id, note) VALUES (1, 'one'), (2, 'two')" );
		$driver->query( 'CREATE TABLE change_auto (id BIGINT NOT NULL AUTO_INCREMENT, note VARCHAR(20), UNIQUE KEY note_unique (note))' );
		$driver->query( "INSERT INTO change_auto (note) VALUES ('one'), ('two')" );
		$driver->query( 'CREATE TABLE change_inline_auto (id BIGINT NOT NULL, note VARCHAR(20), UNIQUE KEY note_unique (note))' );
		$driver->query( "INSERT INTO change_inline_auto (id, note) VALUES (1, 'one'), (2, 'two')" );
		$driver->query( 'CREATE TABLE change_inline_primary (id INT NOT NULL, note VARCHAR(20), KEY note_idx (note))' );
		$driver->query( "INSERT INTO change_inline_primary (id, note) VALUES (1, 'one'), (2, 'two')" );
		$driver->query( 'CREATE TABLE change_inline_unique (id INT NOT NULL, name VARCHAR(20), PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO change_inline_unique (id, name) VALUES (1, 'one'), (2, 'two')" );

		foreach (
			array(
				'change_pk'             => array(
					'sql'     => 'ALTER TABLE change_pk CHANGE id item_id INT NOT NULL',
					'message' => 'primary key column requires a table rebuild',
				),
				'change_auto'           => array(
					'sql'     => 'ALTER TABLE change_auto MODIFY id BIGINT NOT NULL',
					'message' => 'AUTO_INCREMENT column requires a table rebuild',
				),
				'change_inline_auto'    => array(
					'sql'     => 'ALTER TABLE change_inline_auto MODIFY id BIGINT NOT NULL AUTO_INCREMENT',
					'message' => 'CHANGE/MODIFY AUTO_INCREMENT requires a table rebuild',
				),
				'change_inline_primary' => array(
					'sql'     => 'ALTER TABLE change_inline_primary CHANGE id item_id INT NOT NULL PRIMARY KEY',
					'message' => 'CHANGE/MODIFY inline PRIMARY KEY is not supported',
				),
				'change_inline_unique'  => array(
					'sql'     => 'ALTER TABLE change_inline_unique MODIFY name VARCHAR(20) UNIQUE',
					'message' => 'CHANGE/MODIFY inline UNIQUE is not supported',
				),
			) as $table_name => $case
		) {
			$before = $this->alter_table_inline_constraint_guard_snapshot( $driver, $table_name );

			$this->assertDriverQueryRejected( $driver, $case['sql'], $case['message'] );
			$this->assertSame(
				$before,
				$this->alter_table_inline_constraint_guard_snapshot( $driver, $table_name ),
				'CHANGE/MODIFY rejection mutated state for SQL: ' . $case['sql']
			);
			$this->assert_duckdb_connection_usable( $driver );
		}
	}

	public function test_alter_table_drop_column_rejects_last_column(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_col_last (only_col INT)' );

		try {
			$driver->query( 'ALTER TABLE drop_col_last DROP COLUMN only_col' );
			$this->fail( 'Expected DROP COLUMN last-column rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DROP COLUMN cannot remove the last column', $e->getMessage() );
		}
	}

	public function test_alter_table_drop_primary_key_column_rejects_check_references_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_col_check_guard (
				id INT NOT NULL,
				name VARCHAR(20),
				PRIMARY KEY (id),
				CONSTRAINT id_positive CHECK (id > 0)
			)'
		);
		$driver->query( "INSERT INTO drop_col_check_guard (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_check_guard' );
		try {
			$driver->query( 'ALTER TABLE drop_col_check_guard DROP COLUMN id' );
			$this->fail( 'Expected DROP COLUMN CHECK reference rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "CHECK constraint 'id_positive' references it", $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_check_guard' ) );
	}

	public function test_alter_table_drop_primary_key_column_rejects_foreign_key_references_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_col_fk_parent (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE drop_col_fk_child (
				id INT NOT NULL,
				parent_id INT NOT NULL,
				name VARCHAR(20),
				PRIMARY KEY (id, parent_id),
				CONSTRAINT child_parent_fk FOREIGN KEY (parent_id) REFERENCES drop_col_fk_parent (id)
			)'
		);
		$driver->query( 'INSERT INTO drop_col_fk_parent (id) VALUES (1)' );
		$driver->query( "INSERT INTO drop_col_fk_child (id, parent_id, name) VALUES (10, 1, 'a')" );

		$before = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'drop_col_fk_child' );
		try {
			$driver->query( 'ALTER TABLE drop_col_fk_child DROP COLUMN parent_id' );
			$this->fail( 'Expected DROP COLUMN FOREIGN KEY reference rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "FOREIGN KEY constraint 'child_parent_fk' references it", $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'drop_col_fk_child' ) );
	}

	public function test_alter_table_drop_primary_key_column_rejects_active_transaction_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_col_pk_tx (id INT PRIMARY KEY, name VARCHAR(20))' );
		$driver->query( "INSERT INTO drop_col_pk_tx (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_pk_tx' );
		$driver->query( 'BEGIN' );
		try {
			$driver->query( 'ALTER TABLE drop_col_pk_tx DROP COLUMN id' );
			$this->fail( 'Expected active transaction DROP COLUMN rebuild rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DROP COLUMN cannot run inside an active DuckDB transaction', $e->getMessage() );
		}
		$driver->query( 'ROLLBACK' );

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_pk_tx' ) );
	}

	public function test_alter_table_drop_primary_key_column_rejects_multi_action_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_col_pk_multi_guard (id INT PRIMARY KEY, name VARCHAR(20))' );
		$driver->query( "INSERT INTO drop_col_pk_multi_guard (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_pk_multi_guard' );
		foreach (
			array(
				'ALTER TABLE drop_col_pk_multi_guard DROP COLUMN id, ADD COLUMN should_not_exist INT',
				'ALTER TABLE drop_col_pk_multi_guard ADD COLUMN should_not_exist INT, DROP COLUMN id',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action DROP COLUMN rebuild rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'DROP COLUMN requiring a table rebuild cannot be combined with other ALTER TABLE actions', $e->getMessage() );
			}

			$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_col_pk_multi_guard' ) );
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
				'ALTER TABLE protected_target DROP PRIMARY KEY' => "Unknown index 'PRIMARY'",
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

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE add_column_primary_guard (
				name VARCHAR(100) NOT NULL,
				slug VARCHAR(100),
				UNIQUE KEY name_unique (name),
				KEY slug_idx (slug)
			)'
		);
		$driver->query( "INSERT INTO add_column_primary_guard (name, slug) VALUES ('Ada', 'ada'), ('Grace', 'grace')" );

		$before = $this->alter_table_inline_constraint_guard_snapshot( $driver, 'add_column_primary_guard' );
		$this->assertDriverQueryRejected(
			$driver,
			'ALTER TABLE add_column_primary_guard ADD COLUMN id INT PRIMARY KEY',
			'ADD COLUMN PRIMARY KEY is not supported'
		);

		$this->assertSame( $before, $this->alter_table_inline_constraint_guard_snapshot( $driver, 'add_column_primary_guard' ) );
		$this->assert_duckdb_connection_usable( $driver );
	}

	public function test_alter_table_add_column_inline_unique_updates_metadata_and_enforces_uniqueness(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE add_column_unique_inline (id INT NOT NULL, name VARCHAR(20), PRIMARY KEY (id))' );
		$driver->query( "INSERT INTO add_column_unique_inline (id, name) VALUES (1, 'one'), (2, 'two')" );

		$result = $driver->query( 'ALTER TABLE add_column_unique_inline ADD COLUMN slug VARCHAR(20) UNIQUE' );

		$this->assertSame( 0, $result->rowCount() );
		$this->assertSame(
			array(
				array(
					'id'   => 1,
					'name' => 'one',
					'slug' => null,
				),
				array(
					'id'   => 2,
					'name' => 'two',
					'slug' => null,
				),
			),
			$driver->query( 'SELECT id, name, slug FROM add_column_unique_inline ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				'id'   => 'PRI',
				'name' => '',
				'slug' => 'UNI',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM add_column_unique_inline' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);

		$snapshot = $this->alter_table_inline_constraint_guard_snapshot( $driver, 'add_column_unique_inline' );
		$this->assertContains( 'slug', array_column( $snapshot['indexes'], 'Key_name' ) );
		$this->assertStringContainsString( 'UNIQUE KEY `slug` (`slug`)', $snapshot['show_create'][0]['Create Table'] );
		$this->assertContains(
			array(
				'CONSTRAINT_NAME' => 'slug',
				'CONSTRAINT_TYPE' => 'UNIQUE',
				'ENFORCED'        => 'YES',
			),
			$snapshot['table_constraints']
		);
		$this->assertContains(
			array(
				'INDEX_NAME'   => 'slug',
				'NON_UNIQUE'   => 0,
				'SEQ_IN_INDEX' => 1,
				'COLUMN_NAME'  => 'slug',
			),
			$snapshot['statistics']
		);
		$this->assertContains(
			array(
				'CONSTRAINT_NAME'  => 'slug',
				'COLUMN_NAME'      => 'slug',
				'ORDINAL_POSITION' => 1,
			),
			$snapshot['key_column_usage']
		);

		$driver->query( "UPDATE add_column_unique_inline SET slug = 'one' WHERE id = 1" );
		$driver->query( "UPDATE add_column_unique_inline SET slug = 'two' WHERE id = 2" );
		$this->assertDriverQueryRejected(
			$driver,
			"INSERT INTO add_column_unique_inline (id, name, slug) VALUES (3, 'duplicate', 'one')",
			'Failed to execute DuckDB INSERT'
		);
		$this->assert_duckdb_connection_usable( $driver );
	}

	public function test_alter_table_add_check_constraint_rebuilds_table_metadata_and_indexes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_lifecycle (
				id INT,
				amount INT,
				label VARCHAR(20),
				CONSTRAINT existing_check CHECK (amount >= 0),
				UNIQUE KEY label_unique (label),
				KEY amount_idx (amount)
			)'
		);
		$driver->query( "INSERT INTO alter_check_lifecycle (id, amount, label) VALUES (1, 10, 'a'), (2, 20, 'b')" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_lifecycle ADD CONSTRAINT amount_limit CHECK (amount < 100)' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_lifecycle ADD CHECK (id IS NULL OR id >= 0)' )->rowCount() );

		$constraints = $driver->query(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_check_lifecycle'
			ORDER BY constraint_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'alter_check_lifecycle_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_limit',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'existing_check',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'CONSTRAINT_NAME' => 'label_unique',
					'CONSTRAINT_TYPE' => 'UNIQUE',
					'ENFORCED'        => 'YES',
				),
			),
			$constraints
		);

		$check_clauses = array_column(
			$driver->query(
				"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'CHECK_CLAUSE',
			'CONSTRAINT_NAME'
		);
		$this->assertSame(
			array(
				'alter_check_lifecycle_chk_1' => 'id IS NULL OR id >= 0',
				'amount_limit'                => 'amount < 100',
				'existing_check'              => 'amount >= 0',
			),
			$check_clauses
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE alter_check_lifecycle' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( 'CONSTRAINT `existing_check` CHECK (amount >= 0)', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `amount_limit` CHECK (amount < 100)', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `alter_check_lifecycle_chk_1` CHECK (id IS NULL OR id >= 0)', $create_rows[0]['Create Table'] );

		$this->assertSame(
			array( 'amount_idx', 'label_unique' ),
			array_values( array_unique( array_column( $driver->query( 'SHOW INDEX FROM alter_check_lifecycle' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' ) ) )
		);

		$driver->query( "INSERT INTO alter_check_lifecycle (id, amount, label) VALUES (3, 30, 'c')" );

		try {
			$driver->query( "INSERT INTO alter_check_lifecycle (id, amount, label) VALUES (4, 150, 'd')" );
			$this->fail( 'Expected added CHECK constraint to reject future inserts.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( "UPDATE alter_check_lifecycle SET amount = -1 WHERE label = 'c'" );
			$this->fail( 'Expected preserved CHECK constraint to reject future updates.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		try {
			$driver->query( "INSERT INTO alter_check_lifecycle (id, amount, label) VALUES (5, 50, 'a')" );
			$this->fail( 'Expected rebuilt unique index to reject duplicate labels.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_add_check_not_enforced_records_metadata_without_rebuild(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_not_enforced (
				id INT,
				amount INT,
				CONSTRAINT amount_positive CHECK (amount > 0),
				KEY amount_idx (amount)
			)'
		);
		$driver->query( 'INSERT INTO alter_check_not_enforced (id, amount) VALUES (1, 20)' );

		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_check_not_enforced ADD CONSTRAINT amount_less_than_five CHECK (amount < 5) NOT ENFORCED' )->rowCount()
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$driver->get_last_duckdb_queries(),
					function ( string $sql ): bool {
						return false !== strpos( $sql, '__wp_duckdb_rebuild_' );
					}
				)
			)
		);
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_not_enforced ADD CHECK (id > 10) NOT ENFORCED' )->rowCount() );

		$this->assertSame( 1, $driver->query( 'INSERT INTO alter_check_not_enforced (id, amount) VALUES (2, 20)' )->rowCount() );
		try {
			$driver->query( 'INSERT INTO alter_check_not_enforced (id, amount) VALUES (3, -1)' );
			$this->fail( 'Expected enforced CHECK constraint to remain enforced after metadata-only CHECK additions.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'alter_check_not_enforced_chk_1',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_less_than_five',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'NO',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_check_not_enforced'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'alter_check_not_enforced_chk_1',
					'CHECK_CLAUSE'    => 'id > 10',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_less_than_five',
					'CHECK_CLAUSE'    => 'amount < 5',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CHECK_CLAUSE'    => 'amount > 0',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE alter_check_not_enforced' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringContainsString(
			'CONSTRAINT `amount_less_than_five` CHECK (amount < 5) /*!80016 NOT ENFORCED */',
			$create_rows[0]['Create Table']
		);
		$this->assertStringContainsString(
			'CONSTRAINT `alter_check_not_enforced_chk_1` CHECK (id > 10) /*!80016 NOT ENFORCED */',
			$create_rows[0]['Create Table']
		);
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $create_rows[0]['Create Table'] );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_not_enforced DROP CHECK amount_less_than_five' )->rowCount() );
		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_check_not_enforced DROP CONSTRAINT alter_check_not_enforced_chk_1' )->rowCount()
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_check_not_enforced'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$create_rows = $driver->query( 'SHOW CREATE TABLE alter_check_not_enforced' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'NOT ENFORCED', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'KEY `amount_idx` (`amount`)', $create_rows[0]['Create Table'] );
		$this->assertSame( 1, $driver->query( 'INSERT INTO alter_check_not_enforced (id, amount) VALUES (4, 20)' )->rowCount() );
	}

	public function test_alter_table_add_check_constraint_violation_rolls_back_schema_data_and_indexes(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_rollback (
				id INT,
				amount INT,
				label VARCHAR(20),
				CONSTRAINT existing_check CHECK (amount >= 0),
				UNIQUE KEY label_unique (label),
				KEY amount_idx (amount)
			)'
		);
		$driver->query( "INSERT INTO alter_check_rollback (id, amount, label) VALUES (1, 25, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_rollback' );

		try {
			$driver->query( 'ALTER TABLE alter_check_rollback ADD CONSTRAINT too_small CHECK (amount < 10)' );
			$this->fail( 'Expected ADD CHECK to reject existing rows that violate the new constraint.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_rollback' ) );

		$driver->query( "INSERT INTO alter_check_rollback (id, amount, label) VALUES (2, 30, 'b')" );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'amount' => 25,
					'label'  => 'a',
				),
				array(
					'id'     => 2,
					'amount' => 30,
					'label'  => 'b',
				),
			),
			$driver->query( 'SELECT id, amount, label FROM alter_check_rollback ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_add_check_rollback_preserves_auto_increment_sequence_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_auto_increment (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				amount INT,
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( 'INSERT INTO alter_check_auto_increment (amount) VALUES (10), (20)' );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_auto_increment' );

		try {
			$driver->query( 'ALTER TABLE alter_check_auto_increment ADD CONSTRAINT too_small CHECK (amount < 15)' );
			$this->fail( 'Expected ADD CHECK to reject existing rows that violate the new constraint.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_auto_increment' ) );

		$driver->query( 'INSERT INTO alter_check_auto_increment (amount) VALUES (30)' );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'amount' => 10,
				),
				array(
					'id'     => 2,
					'amount' => 20,
				),
				array(
					'id'     => 3,
					'amount' => 30,
				),
			),
			$driver->query( 'SELECT id, amount FROM alter_check_auto_increment ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_check_rebuild_preserves_auto_increment_sequence_state(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_auto_increment_rebuild (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				amount INT,
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( 'INSERT INTO alter_check_auto_increment_rebuild (amount) VALUES (10), (20)' );
		$driver->query( 'ALTER TABLE alter_check_auto_increment_rebuild ADD CONSTRAINT amount_limit CHECK (amount < 100)' );
		$driver->query( 'INSERT INTO alter_check_auto_increment_rebuild (amount) VALUES (30)' );
		$driver->query( 'ALTER TABLE alter_check_auto_increment_rebuild DROP CHECK amount_limit' );
		$driver->query( 'INSERT INTO alter_check_auto_increment_rebuild (amount) VALUES (40)' );

		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'amount' => 10,
				),
				array(
					'id'     => 2,
					'amount' => 20,
				),
				array(
					'id'     => 3,
					'amount' => 30,
				),
				array(
					'id'     => 4,
					'amount' => 40,
				),
			),
			$driver->query( 'SELECT id, amount FROM alter_check_auto_increment_rebuild ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_drop_check_constraint_rebuilds_table_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_drop (
				id INT,
				label VARCHAR(20),
				CONSTRAINT c1 CHECK (id > 0),
				CONSTRAINT c2 CHECK (id < 10),
				KEY id_idx (id)
			)'
		);
		$driver->query( "INSERT INTO alter_check_drop (id, label) VALUES (5, 'a')" );

		try {
			$driver->query( "INSERT INTO alter_check_drop (id, label) VALUES (0, 'blocked')" );
			$this->fail( 'Expected original CHECK constraint to reject invalid rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_drop DROP CONSTRAINT c1' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_drop DROP CHECK c2' )->rowCount() );

		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_check_drop' AND constraint_type = 'CHECK'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_rows = $driver->query( 'SHOW CREATE TABLE alter_check_drop' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringNotContainsString( 'CONSTRAINT `c1`', $create_rows[0]['Create Table'] );
		$this->assertStringNotContainsString( 'CONSTRAINT `c2`', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'KEY `id_idx` (`id`)', $create_rows[0]['Create Table'] );

		$driver->query( "INSERT INTO alter_check_drop (id, label) VALUES (0, 'after_drop'), (20, 'also_after_drop')" );
		$this->assertSame(
			array(
				array( 'id' => 0 ),
				array( 'id' => 5 ),
				array( 'id' => 20 ),
			),
			$driver->query( 'SELECT id FROM alter_check_drop ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_check_constraint_actions_target_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE shadow_check (id INT, amount INT, CONSTRAINT persistent_positive CHECK (amount > 0))' );
		$driver->query( 'INSERT INTO shadow_check (id, amount) VALUES (1, 10)' );
		$driver->query( 'CREATE TEMPORARY TABLE shadow_check (id INT, amount INT, CONSTRAINT temp_non_negative CHECK (id >= 0))' );
		$driver->query( 'INSERT INTO shadow_check (id, amount) VALUES (2, 20)' );

		$driver->query( 'ALTER TABLE shadow_check ADD CONSTRAINT temp_amount_limit CHECK (amount < 100)' );

		$create_rows = $driver->query( 'SHOW CREATE TABLE shadow_check' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringStartsWith( 'CREATE TEMPORARY TABLE `shadow_check`', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `temp_non_negative` CHECK (id >= 0)', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `temp_amount_limit` CHECK (amount < 100)', $create_rows[0]['Create Table'] );
		$this->assertStringNotContainsString( 'persistent_positive', $create_rows[0]['Create Table'] );

		try {
			$driver->query( 'INSERT INTO shadow_check (id, amount) VALUES (3, 150)' );
			$this->fail( 'Expected temporary CHECK constraint to reject invalid rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$driver->query( 'ALTER TABLE shadow_check DROP CHECK temp_non_negative' );
		$driver->query( 'INSERT INTO shadow_check (id, amount) VALUES (-1, 30)' );
		$driver->query( 'DROP TEMPORARY TABLE shadow_check' );

		$create_rows = $driver->query( 'SHOW CREATE TABLE shadow_check' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertStringStartsWith( 'CREATE TABLE `shadow_check`', $create_rows[0]['Create Table'] );
		$this->assertStringContainsString( 'CONSTRAINT `persistent_positive` CHECK (amount > 0)', $create_rows[0]['Create Table'] );
		$this->assertStringNotContainsString( 'temp_amount_limit', $create_rows[0]['Create Table'] );
		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'amount' => 10,
				),
			),
			$driver->query( 'SELECT id, amount FROM shadow_check ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_add_drop_check_rejects_duplicate_and_missing_names_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_names (
				id INT,
				amount INT,
				CONSTRAINT existing_check CHECK (amount >= 0),
				KEY amount_idx (amount)
			)'
		);
		$driver->query( 'INSERT INTO alter_check_names (id, amount) VALUES (1, 10)' );

		foreach (
			array(
				'ALTER TABLE alter_check_names ADD CONSTRAINT existing_check CHECK (id > 0)' => 'Duplicate CHECK constraint name',
				'ALTER TABLE alter_check_names ADD CONSTRAINT EXISTING_CHECK CHECK (id > 0)' => 'Duplicate CHECK constraint name',
				'ALTER TABLE alter_check_names DROP CONSTRAINT missing_check' => "Unknown constraint 'missing_check'",
			) as $sql => $message
		) {
			$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_names' );

			try {
				$driver->query( $sql );
				$this->fail( 'Expected ALTER TABLE CHECK name rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_names' ),
				'ALTER TABLE CHECK name rejection mutated schema or data for SQL: ' . $sql
			);
		}

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_names' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_names DROP CHECK missing_check' )->rowCount() );
		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_names' ) );
	}

	public function test_alter_table_add_check_allows_names_used_by_other_constraint_types(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_check_name_parent (id INT PRIMARY KEY)' );
		$driver->query(
			'CREATE TABLE alter_check_name_reuse (
				id INT,
				parent_id INT,
				UNIQUE KEY reused_unique (id),
				CONSTRAINT reused_fk FOREIGN KEY (parent_id) REFERENCES alter_check_name_parent (id)
			)'
		);
		$driver->query( 'ALTER TABLE alter_check_name_reuse ADD CONSTRAINT reused_unique CHECK (id > 0)' );
		$driver->query( 'ALTER TABLE alter_check_name_reuse ADD CONSTRAINT reused_fk CHECK (parent_id IS NULL OR parent_id > 0)' );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'reused_fk',
					'CONSTRAINT_TYPE' => 'CHECK',
				),
				array(
					'CONSTRAINT_NAME' => 'reused_unique',
					'CONSTRAINT_TYPE' => 'CHECK',
				),
				array(
					'CONSTRAINT_NAME' => 'reused_fk',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
				),
				array(
					'CONSTRAINT_NAME' => 'reused_unique',
					'CONSTRAINT_TYPE' => 'UNIQUE',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_check_name_reuse'
				ORDER BY constraint_type, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'ALTER TABLE alter_check_name_reuse DROP CONSTRAINT reused_fk' );
			$this->fail( 'Expected generic DROP CONSTRAINT with cross-type duplicate names to be ambiguous.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Ambiguous constraint 'reused_fk'", $e->getMessage() );
		}
	}

	public function test_alter_table_check_constraint_actions_reject_multi_action_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_check_multi (id INT, amount INT, CONSTRAINT amount_positive CHECK (amount > 0))' );
		$driver->query( 'INSERT INTO alter_check_multi (id, amount) VALUES (1, 20)' );

		foreach (
			array(
				'ALTER TABLE alter_check_multi ADD COLUMN should_not_exist INT DEFAULT 2, ADD CONSTRAINT too_small CHECK (amount < 10)',
				'ALTER TABLE alter_check_multi ADD CONSTRAINT too_small CHECK (amount < 10), DROP COLUMN amount',
				'ALTER TABLE alter_check_multi DROP CHECK amount_positive, ADD COLUMN should_not_exist INT DEFAULT 2',
			) as $sql
		) {
			$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_multi' );

			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action ALTER TABLE CHECK rebuild rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'ADD/DROP CHECK cannot be combined with other ALTER TABLE actions', $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_multi' ),
				'Multi-action ALTER TABLE CHECK rebuild rejection mutated schema or data for SQL: ' . $sql
			);
		}
	}

	public function test_alter_table_check_rebuild_preserves_foreign_keys(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_check_fk_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_check_fk_parent (id) VALUES (1)' );
		$driver->query(
			'CREATE TABLE alter_check_fk_child (
				id INT,
				parent_id INT,
				amount INT,
				CONSTRAINT child_fk FOREIGN KEY (parent_id) REFERENCES alter_check_fk_parent (id),
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( 'INSERT INTO alter_check_fk_child (id, parent_id, amount) VALUES (1, 1, 10)' );

		$driver->query( 'ALTER TABLE alter_check_fk_child ADD CONSTRAINT amount_limit CHECK (amount < 100)' );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'amount_limit',
					'CONSTRAINT_TYPE' => 'CHECK',
				),
				array(
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
				),
				array(
					'CONSTRAINT_NAME' => 'child_fk',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_check_fk_child'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString(
			'CONSTRAINT `child_fk` FOREIGN KEY (`parent_id`) REFERENCES `alter_check_fk_parent` (`id`)',
			$driver->query( 'SHOW CREATE TABLE alter_check_fk_child' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		try {
			$driver->query( 'INSERT INTO alter_check_fk_child (id, parent_id, amount) VALUES (2, 2, 20)' );
			$this->fail( 'Expected rebuilt foreign key to reject missing parent rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		$driver->query( 'ALTER TABLE alter_check_fk_child DROP CHECK amount_limit' );

		try {
			$driver->query( 'INSERT INTO alter_check_fk_child (id, parent_id, amount) VALUES (3, 3, 30)' );
			$this->fail( 'Expected foreign key to remain enforced after DROP CHECK rebuild.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		$driver->query( 'INSERT INTO alter_check_fk_child (id, parent_id, amount) VALUES (4, 1, 150)' );
		$this->assertSame(
			array(
				array(
					'id'        => 1,
					'parent_id' => 1,
					'amount'    => 10,
				),
				array(
					'id'        => 4,
					'parent_id' => 1,
					'amount'    => 150,
				),
			),
			$driver->query( 'SELECT id, parent_id, amount FROM alter_check_fk_child ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_check_rebuild_rejects_referenced_parent_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE alter_check_parent_guard (
				id INT PRIMARY KEY,
				amount INT,
				CONSTRAINT parent_positive CHECK (amount > 0)
			)'
		);
		$driver->query(
			'CREATE TABLE alter_check_child_guard (
				id INT,
				parent_id INT,
				CONSTRAINT child_parent_fk FOREIGN KEY (parent_id) REFERENCES alter_check_parent_guard (id)
			)'
		);

		$before = $this->alter_table_referenced_parent_snapshot( $driver );

		try {
			$driver->query( 'ALTER TABLE alter_check_parent_guard ADD CONSTRAINT parent_limit CHECK (amount < 100)' );
			$this->fail( 'Expected referenced parent ADD CHECK rebuild to be rejected before mutation.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'referenced by FOREIGN KEY', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_referenced_parent_snapshot( $driver ) );

		$driver->query( 'INSERT INTO alter_check_parent_guard (id, amount) VALUES (1, 10)' );
		$driver->query( 'INSERT INTO alter_check_child_guard (id, parent_id) VALUES (10, 1)' );
		$before = $this->alter_table_referenced_parent_snapshot( $driver );

		try {
			$driver->query( 'ALTER TABLE alter_check_parent_guard DROP CHECK parent_positive' );
			$this->fail( 'Expected referenced parent DROP CHECK rebuild to be rejected before mutation.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'referenced by FOREIGN KEY', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_referenced_parent_snapshot( $driver ) );
	}

	public function test_alter_table_add_drop_check_rejects_active_transactions_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_check_tx (id INT, CONSTRAINT c1 CHECK (id >= 0), KEY id_idx (id))' );
		$driver->query( 'INSERT INTO alter_check_tx (id) VALUES (1)' );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_tx' );

		$driver->query( 'BEGIN' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_check_tx DROP CHECK missing_check' )->rowCount() );
		foreach (
			array(
				'ALTER TABLE alter_check_tx ADD CONSTRAINT c2 CHECK (id < 10)',
				'ALTER TABLE alter_check_tx DROP CHECK c1',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected active transaction CHECK rebuild rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'cannot run inside an active DuckDB transaction', $e->getMessage() );
			}
		}
		$driver->query( 'ROLLBACK' );

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_tx' ) );
	}

	public function test_alter_table_add_primary_key_rebuilds_table_metadata_and_enforcement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE add_pk_direct (
				id INT,
				name VARCHAR(20) NOT NULL,
				amount INT,
				UNIQUE KEY name_unique (name),
				KEY amount_idx (amount),
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( "INSERT INTO add_pk_direct (id, name, amount) VALUES (1, 'a', 10), (2, 'b', 20)" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE add_pk_direct ADD PRIMARY KEY (id)' )->rowCount() );

		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'name'   => 'a',
					'amount' => 10,
				),
				array(
					'id'     => 2,
					'name'   => 'b',
					'amount' => 20,
				),
			),
			$driver->query( 'SELECT id, name, amount FROM add_pk_direct ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$columns = $driver->query( 'SHOW COLUMNS FROM add_pk_direct' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( 'PRI', array_column( $columns, 'Key', 'Field' )['id'] );
		$this->assertSame( 'NO', array_column( $columns, 'Null', 'Field' )['id'] );
		$this->assertSame( 'UNI', array_column( $columns, 'Key', 'Field' )['name'] );
		$this->assertSame( 'MUL', array_column( $columns, 'Key', 'Field' )['amount'] );

		$index_rows = $driver->query( 'SHOW INDEX FROM add_pk_direct' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertContains( 'PRIMARY', array_column( $index_rows, 'Key_name' ) );
		$this->assertContains( 'name_unique', array_column( $index_rows, 'Key_name' ) );
		$this->assertContains( 'amount_idx', array_column( $index_rows, 'Key_name' ) );

		$create_sql = $driver->query( 'SHOW CREATE TABLE add_pk_direct' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringContainsString( 'PRIMARY KEY (`id`)', $create_sql );
		$this->assertStringContainsString( 'UNIQUE KEY `name_unique` (`name`)', $create_sql );
		$this->assertStringContainsString( 'KEY `amount_idx` (`amount`)', $create_sql );
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $create_sql );

		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME' => 'PRIMARY',
					'CONSTRAINT_TYPE' => 'PRIMARY KEY',
					'ENFORCED'        => 'YES',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name = 'add_pk_direct'
					AND constraint_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'  => 'PRIMARY',
					'COLUMN_NAME'      => 'id',
					'ORDINAL_POSITION' => 1,
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name = 'add_pk_direct'
					AND constraint_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'INDEX_NAME'  => 'PRIMARY',
					'COLUMN_NAME' => 'id',
					'NON_UNIQUE'  => 0,
				),
			),
			$driver->query(
				"SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'add_pk_direct'
					AND index_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		foreach (
			array(
				"INSERT INTO add_pk_direct (id, name, amount) VALUES (1, 'duplicate', 30)",
				"INSERT INTO add_pk_direct (id, name, amount) VALUES (NULL, 'null-id', 30)",
				"UPDATE add_pk_direct SET id = 1 WHERE name = 'b'",
				"UPDATE add_pk_direct SET id = NULL WHERE name = 'b'",
				"INSERT INTO add_pk_direct (id, name, amount) VALUES (3, 'a', 30)",
				"INSERT INTO add_pk_direct (id, name, amount) VALUES (4, 'd', 0)",
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected added PRIMARY KEY, secondary UNIQUE, or CHECK enforcement to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'Failed to execute DuckDB', $e->getMessage() );
			}
		}
	}

	public function test_alter_table_add_composite_primary_key_supports_named_constraints(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE add_pk_composite (site_id INT NOT NULL, option_id INT NOT NULL, label VARCHAR(20))' );
		$driver->query( "INSERT INTO add_pk_composite (site_id, option_id, label) VALUES (1, 1, 'a'), (1, 2, 'b')" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE add_pk_composite ADD CONSTRAINT ignored_name PRIMARY KEY (site_id, option_id)' )->rowCount() );

		$this->assertSame(
			array(
				array(
					'Key_name'     => 'PRIMARY',
					'Seq_in_index' => 1,
					'Column_name'  => 'site_id',
				),
				array(
					'Key_name'     => 'PRIMARY',
					'Seq_in_index' => 2,
					'Column_name'  => 'option_id',
				),
			),
			array_map(
				function ( array $row ): array {
					return array(
						'Key_name'     => $row['Key_name'],
						'Seq_in_index' => $row['Seq_in_index'],
						'Column_name'  => $row['Column_name'],
					);
				},
				$driver->query( 'SHOW INDEX FROM add_pk_composite' )->fetchAll( PDO::FETCH_ASSOC )
			)
		);
		$this->assertStringContainsString(
			'PRIMARY KEY (`site_id`, `option_id`)',
			$driver->query( 'SHOW CREATE TABLE add_pk_composite' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		try {
			$driver->query( "INSERT INTO add_pk_composite (site_id, option_id, label) VALUES (1, 1, 'duplicate')" );
			$this->fail( 'Expected composite PRIMARY KEY to reject duplicate rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_add_primary_key_rejects_invalid_existing_state_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE add_pk_duplicate (id INT, name VARCHAR(20), KEY name_idx (name))' );
		$driver->query( "INSERT INTO add_pk_duplicate (id, name) VALUES (1, 'a'), (1, 'b')" );
		$driver->query( 'CREATE TABLE add_pk_null (id INT, name VARCHAR(20), KEY name_idx (name))' );
		$driver->query( "INSERT INTO add_pk_null (id, name) VALUES (1, 'a'), (NULL, 'b')" );
		$driver->query( 'CREATE TABLE add_pk_existing (id INT PRIMARY KEY, name VARCHAR(20))' );
		$driver->query( "INSERT INTO add_pk_existing (id, name) VALUES (1, 'a')" );

		foreach (
			array(
				'add_pk_duplicate' => array(
					'sql'     => 'ALTER TABLE add_pk_duplicate ADD PRIMARY KEY (id)',
					'message' => 'duplicate key values',
				),
				'add_pk_null'      => array(
					'sql'     => 'ALTER TABLE add_pk_null ADD PRIMARY KEY (id)',
					'message' => 'NULL values',
				),
				'add_pk_existing'  => array(
					'sql'     => 'ALTER TABLE add_pk_existing ADD PRIMARY KEY (name)',
					'message' => 'Duplicate primary key',
				),
				'add_pk_missing'   => array(
					'sql'     => 'ALTER TABLE add_pk_duplicate ADD PRIMARY KEY (missing_id)',
					'message' => "Unknown column 'missing_id'",
				),
			) as $table_name => $case
		) {
			$snapshot_table = 'add_pk_missing' === $table_name ? 'add_pk_duplicate' : $table_name;
			$before         = $this->alter_table_check_lifecycle_snapshot( $driver, $snapshot_table );

			try {
				$driver->query( $case['sql'] );
				$this->fail( 'Expected invalid ADD PRIMARY KEY state to reject SQL: ' . $case['sql'] );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $case['message'], $e->getMessage() );
			}

			$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, $snapshot_table ) );
		}
	}

	public function test_alter_table_add_primary_key_rejects_active_transaction_and_multi_action_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE add_pk_guard (id INT NOT NULL, name VARCHAR(20))' );
		$driver->query( "INSERT INTO add_pk_guard (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'add_pk_guard' );

		$driver->query( 'BEGIN' );
		try {
			$driver->query( 'ALTER TABLE add_pk_guard ADD PRIMARY KEY (id)' );
			$this->fail( 'Expected active transaction ADD PRIMARY KEY rebuild rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'ADD PRIMARY KEY cannot run inside an active DuckDB transaction', $e->getMessage() );
		}
		$driver->query( 'ROLLBACK' );
		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'add_pk_guard' ) );

		foreach (
			array(
				'ALTER TABLE add_pk_guard ADD PRIMARY KEY (id), ADD COLUMN should_not_exist INT',
				'ALTER TABLE add_pk_guard ADD COLUMN should_not_exist INT, ADD PRIMARY KEY (id)',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action ADD PRIMARY KEY rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'ADD PRIMARY KEY cannot be combined with other ALTER TABLE actions', $e->getMessage() );
			}

			$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'add_pk_guard' ) );
		}
	}

	public function test_alter_table_add_primary_key_targets_temporary_shadow_table(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE add_pk_shadow (id INT, label VARCHAR(20))' );
		$driver->query( "INSERT INTO add_pk_shadow (id, label) VALUES (1, 'persistent')" );
		$driver->query( 'CREATE TEMPORARY TABLE add_pk_shadow (id INT, label VARCHAR(20))' );
		$driver->query( "INSERT INTO add_pk_shadow (id, label) VALUES (2, 'temporary')" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE add_pk_shadow ADD PRIMARY KEY (id)' )->rowCount() );
		$this->assertContains( 'PRIMARY', array_column( $driver->query( 'SHOW INDEX FROM add_pk_shadow' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' ) );

		try {
			$driver->query( "INSERT INTO add_pk_shadow (id, label) VALUES (2, 'duplicate-temp')" );
			$this->fail( 'Expected temporary shadow table PRIMARY KEY to reject duplicate rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		$driver->query( 'DROP TEMPORARY TABLE add_pk_shadow' );
		$this->assertNotContains( 'PRIMARY', array_column( $driver->query( 'SHOW INDEX FROM add_pk_shadow' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' ) );
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'label' => 'persistent',
				),
			),
			$driver->query( 'SELECT id, label FROM add_pk_shadow ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_drop_primary_key_preserves_rows_metadata_and_secondary_enforcement(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE drop_pk_direct (
				id INT NOT NULL,
				name VARCHAR(20) NOT NULL,
				amount INT,
				PRIMARY KEY (id),
				UNIQUE KEY name_unique (name),
				KEY amount_idx (amount),
				CONSTRAINT amount_positive CHECK (amount > 0)
			)'
		);
		$driver->query( "INSERT INTO drop_pk_direct (id, name, amount) VALUES (1, 'a', 10), (2, 'b', 20)" );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE drop_pk_direct DROP PRIMARY KEY' )->rowCount() );
		$driver->query( "INSERT INTO drop_pk_direct (id, name, amount) VALUES (1, 'c', 30)" );

		try {
			$driver->query( "INSERT INTO drop_pk_direct (id, name, amount) VALUES (3, 'a', 40)" );
			$this->fail( 'Expected secondary UNIQUE index to remain enforced after DROP PRIMARY KEY.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		try {
			$driver->query( "INSERT INTO drop_pk_direct (id, name, amount) VALUES (4, 'd', 0)" );
			$this->fail( 'Expected CHECK constraint to remain enforced after DROP PRIMARY KEY.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'CHECK constraint failed', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'id'     => 1,
					'name'   => 'a',
					'amount' => 10,
				),
				array(
					'id'     => 1,
					'name'   => 'c',
					'amount' => 30,
				),
				array(
					'id'     => 2,
					'name'   => 'b',
					'amount' => 20,
				),
			),
			$driver->query( 'SELECT id, name, amount FROM drop_pk_direct ORDER BY id, name' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertNotContains( 'PRIMARY', array_column( $driver->query( 'SHOW INDEX FROM drop_pk_direct' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' ) );
		$this->assertSame(
			array(
				'id'     => '',
				'name'   => 'UNI',
				'amount' => 'MUL',
			),
			array_column( $driver->query( 'SHOW COLUMNS FROM drop_pk_direct' )->fetchAll( PDO::FETCH_ASSOC ), 'Key', 'Field' )
		);

		$create_sql = $driver->query( 'SHOW CREATE TABLE drop_pk_direct' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringNotContainsString( 'PRIMARY KEY', $create_sql );
		$this->assertStringContainsString( 'UNIQUE KEY `name_unique` (`name`)', $create_sql );
		$this->assertStringContainsString( 'KEY `amount_idx` (`amount`)', $create_sql );
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $create_sql );

		$this->assertSame(
			array(),
			$driver->query(
				"SELECT *
				FROM information_schema.statistics
				WHERE table_schema = 'wp'
					AND table_name = 'drop_pk_direct'
					AND index_name = 'PRIMARY'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		foreach ( array( 'table_constraints', 'key_column_usage' ) as $table ) {
			$this->assertSame(
				array(),
				$driver->query(
					"SELECT *
					FROM information_schema.{$table}
					WHERE table_schema = 'wp'
						AND table_name = 'drop_pk_direct'
						AND constraint_name = 'PRIMARY'"
				)->fetchAll( PDO::FETCH_ASSOC )
			);
		}
	}

	public function test_alter_table_drop_primary_key_rejects_active_transaction_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_pk_tx (id INT PRIMARY KEY, name VARCHAR(20), KEY name_idx (name))' );
		$driver->query( "INSERT INTO drop_pk_tx (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_tx' );

		$driver->query( 'BEGIN' );
		try {
			$driver->query( 'ALTER TABLE drop_pk_tx DROP PRIMARY KEY' );
			$this->fail( 'Expected active transaction DROP PRIMARY KEY rebuild rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DROP PRIMARY KEY cannot run inside an active DuckDB transaction', $e->getMessage() );
		}
		$driver->query( 'ROLLBACK' );

		$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_tx' ) );
	}

	public function test_alter_table_drop_primary_key_rejects_multi_action_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_pk_multi (id INT PRIMARY KEY, name VARCHAR(20))' );
		$driver->query( "INSERT INTO drop_pk_multi (id, name) VALUES (1, 'a')" );

		$before = $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_multi' );

		foreach (
			array(
				'ALTER TABLE drop_pk_multi DROP PRIMARY KEY, ADD COLUMN should_not_exist INT',
				'ALTER TABLE drop_pk_multi ADD COLUMN should_not_exist INT, DROP PRIMARY KEY',
			) as $sql
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action DROP PRIMARY KEY rejection for SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'DROP PRIMARY KEY cannot be combined with other ALTER TABLE actions', $e->getMessage() );
			}

			$this->assertSame( $before, $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_multi' ) );
		}
	}

	public function test_alter_table_drop_primary_key_rejects_referenced_parent_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE drop_pk_parent_guard (id INT PRIMARY KEY, name VARCHAR(20))' );
		$driver->query( 'CREATE TABLE drop_pk_child_guard (id INT PRIMARY KEY, parent_id INT, CONSTRAINT child_parent_fk FOREIGN KEY (parent_id) REFERENCES drop_pk_parent_guard (id))' );
		$driver->query( "INSERT INTO drop_pk_parent_guard (id, name) VALUES (1, 'parent')" );
		$driver->query( 'INSERT INTO drop_pk_child_guard (id, parent_id) VALUES (10, 1)' );

		$before = array(
			'parent'                  => $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_parent_guard' ),
			'child'                   => $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_child_guard' ),
			'referential_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
		);

		try {
			$driver->query( 'ALTER TABLE drop_pk_parent_guard DROP PRIMARY KEY' );
			$this->fail( 'Expected referenced parent DROP PRIMARY KEY rebuild to be rejected before mutation.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'DROP PRIMARY KEY on table', $e->getMessage() );
			$this->assertStringContainsString( 'referenced by FOREIGN KEY', $e->getMessage() );
		}

		$this->assertSame(
			$before,
			array(
				'parent'                  => $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_parent_guard' ),
				'child'                   => $this->alter_table_check_lifecycle_snapshot( $driver, 'drop_pk_child_guard' ),
				'referential_constraints' => $driver->query(
					"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
					FROM information_schema.referential_constraints
					WHERE constraint_schema = 'wp'
					ORDER BY constraint_name"
				)->fetchAll( PDO::FETCH_ASSOC ),
			)
		);

		try {
			$driver->query( 'INSERT INTO drop_pk_child_guard (id, parent_id) VALUES (11, 2)' );
			$this->fail( 'Expected FK enforcement to remain intact after rejected DROP PRIMARY KEY.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}
	}

	public function test_alter_table_add_foreign_key_constraints_rebuilds_table_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_fk_parent (id) VALUES (1), (2)' );
		$driver->query(
			'CREATE TABLE alter_fk_child_named (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				parent_id INT,
				amount INT,
				label VARCHAR(20),
				CONSTRAINT amount_positive CHECK (amount > 0),
				UNIQUE KEY label_unique (label),
				KEY parent_idx (parent_id)
			)'
		);
		$driver->query( "INSERT INTO alter_fk_child_named (parent_id, amount, label) VALUES (1, 10, 'a'), (2, 20, 'b')" );
		$driver->query( 'CREATE TABLE alter_fk_child_generated (id INT, parent_id INT, KEY parent_idx (parent_id))' );
		$driver->query( 'INSERT INTO alter_fk_child_generated (id, parent_id) VALUES (10, 1)' );

		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_fk_child_named ADD CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_parent (id) ON DELETE RESTRICT ON UPDATE NO ACTION' )->rowCount()
		);
		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_fk_child_generated ADD FOREIGN KEY (parent_id) REFERENCES alter_fk_parent (id)' )->rowCount()
		);

		$this->assertSame(
			array(
				array(
					'TABLE_NAME'      => 'alter_fk_child_generated',
					'CONSTRAINT_NAME' => 'alter_fk_child_generated_ibfk_1',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'TABLE_NAME'      => 'alter_fk_child_named',
					'CONSTRAINT_NAME' => 'amount_positive',
					'CONSTRAINT_TYPE' => 'CHECK',
					'ENFORCED'        => 'YES',
				),
				array(
					'TABLE_NAME'      => 'alter_fk_child_named',
					'CONSTRAINT_NAME' => 'fk_child_parent',
					'CONSTRAINT_TYPE' => 'FOREIGN KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'TABLE_NAME'      => 'alter_fk_child_named',
					'CONSTRAINT_NAME' => 'PRIMARY',
					'CONSTRAINT_TYPE' => 'PRIMARY KEY',
					'ENFORCED'        => 'YES',
				),
				array(
					'TABLE_NAME'      => 'alter_fk_child_named',
					'CONSTRAINT_NAME' => 'label_unique',
					'CONSTRAINT_TYPE' => 'UNIQUE',
					'ENFORCED'        => 'YES',
				),
			),
			$driver->query(
				"SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND table_name IN ('alter_fk_child_named', 'alter_fk_child_generated')
				ORDER BY table_name, constraint_type, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'       => 'alter_fk_child_generated_ibfk_1',
					'TABLE_NAME'            => 'alter_fk_child_generated',
					'REFERENCED_TABLE_NAME' => 'alter_fk_parent',
					'UPDATE_RULE'           => 'NO ACTION',
					'DELETE_RULE'           => 'NO ACTION',
				),
				array(
					'CONSTRAINT_NAME'       => 'fk_child_parent',
					'TABLE_NAME'            => 'alter_fk_child_named',
					'REFERENCED_TABLE_NAME' => 'alter_fk_parent',
					'UPDATE_RULE'           => 'NO ACTION',
					'DELETE_RULE'           => 'RESTRICT',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp'
					AND table_name IN ('alter_fk_child_named', 'alter_fk_child_generated')
				ORDER BY table_name, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'alter_fk_child_generated_ibfk_1',
					'TABLE_NAME'             => 'alter_fk_child_generated',
					'COLUMN_NAME'            => 'parent_id',
					'REFERENCED_TABLE_NAME'  => 'alter_fk_parent',
					'REFERENCED_COLUMN_NAME' => 'id',
				),
				array(
					'CONSTRAINT_NAME'        => 'fk_child_parent',
					'TABLE_NAME'             => 'alter_fk_child_named',
					'COLUMN_NAME'            => 'parent_id',
					'REFERENCED_TABLE_NAME'  => 'alter_fk_parent',
					'REFERENCED_COLUMN_NAME' => 'id',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name IN ('alter_fk_child_named', 'alter_fk_child_generated')
					AND referenced_table_name IS NOT NULL
				ORDER BY table_name, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$named_create = $driver->query( 'SHOW CREATE TABLE alter_fk_child_named' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringContainsString( 'CONSTRAINT `fk_child_parent` FOREIGN KEY (`parent_id`) REFERENCES `alter_fk_parent` (`id`) ON DELETE RESTRICT', $named_create );
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $named_create );
		$this->assertStringContainsString( 'UNIQUE KEY `label_unique` (`label`)', $named_create );
		$this->assertStringContainsString( 'KEY `parent_idx` (`parent_id`)', $named_create );
		$this->assertStringContainsString(
			'CONSTRAINT `alter_fk_child_generated_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `alter_fk_parent` (`id`)',
			$driver->query( 'SHOW CREATE TABLE alter_fk_child_generated' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		$driver->query( "INSERT INTO alter_fk_child_named (parent_id, amount, label) VALUES (1, 30, 'c')" );
		$this->assertSame(
			array(
				array(
					'id'        => 1,
					'parent_id' => 1,
					'amount'    => 10,
					'label'     => 'a',
				),
				array(
					'id'        => 2,
					'parent_id' => 2,
					'amount'    => 20,
					'label'     => 'b',
				),
				array(
					'id'        => 3,
					'parent_id' => 1,
					'amount'    => 30,
					'label'     => 'c',
				),
			),
			$driver->query( 'SELECT id, parent_id, amount, label FROM alter_fk_child_named ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( "INSERT INTO alter_fk_child_named (parent_id, amount, label) VALUES (404, 40, 'blocked')" );
			$this->fail( 'Expected added FOREIGN KEY to reject missing parent rows.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB INSERT', $e->getMessage() );
		}

		try {
			$driver->query( 'DELETE FROM alter_fk_parent WHERE id = 1' );
			$this->fail( 'Expected added FOREIGN KEY to reject referenced parent deletes.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Failed to execute DuckDB DELETE', $e->getMessage() );
		}
	}

	public function test_alter_table_drop_foreign_key_constraints_rebuilds_table_and_metadata(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_drop_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_fk_drop_parent (id) VALUES (1)' );
		$driver->query(
			'CREATE TABLE alter_fk_drop_by_key (
				id INT,
				parent_id INT,
				amount INT,
				CONSTRAINT fk_drop_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_drop_parent (id),
				CONSTRAINT amount_positive CHECK (amount > 0),
				KEY parent_idx (parent_id)
			)'
		);
		$driver->query(
			'CREATE TABLE alter_fk_drop_by_constraint (
				id INT,
				parent_id INT,
				CONSTRAINT fk_constraint_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_drop_parent (id)
			)'
		);
		$driver->query( 'INSERT INTO alter_fk_drop_by_key (id, parent_id, amount) VALUES (1, 1, 10)' );
		$driver->query( 'INSERT INTO alter_fk_drop_by_constraint (id, parent_id) VALUES (2, 1)' );

		$before = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_drop_by_key' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_fk_drop_by_key DROP FOREIGN KEY missing_fk' )->rowCount() );
		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_drop_by_key' ) );

		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_fk_drop_by_key DROP FOREIGN KEY fk_drop_parent' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_fk_drop_by_constraint DROP CONSTRAINT fk_constraint_parent' )->rowCount() );

		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp'
					AND table_name IN ('alter_fk_drop_by_key', 'alter_fk_drop_by_constraint')"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(),
			$driver->query(
				"SELECT CONSTRAINT_NAME
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name IN ('alter_fk_drop_by_key', 'alter_fk_drop_by_constraint')
					AND referenced_table_name IS NOT NULL"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$key_create = $driver->query( 'SHOW CREATE TABLE alter_fk_drop_by_key' )->fetch( PDO::FETCH_ASSOC )['Create Table'];
		$this->assertStringNotContainsString( 'fk_drop_parent', $key_create );
		$this->assertStringContainsString( 'CONSTRAINT `amount_positive` CHECK (amount > 0)', $key_create );
		$this->assertStringContainsString( 'KEY `parent_idx` (`parent_id`)', $key_create );
		$this->assertStringNotContainsString(
			'fk_constraint_parent',
			$driver->query( 'SHOW CREATE TABLE alter_fk_drop_by_constraint' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		$driver->query( 'INSERT INTO alter_fk_drop_by_key (id, parent_id, amount) VALUES (3, 404, 20)' );
		$driver->query( 'INSERT INTO alter_fk_drop_by_constraint (id, parent_id) VALUES (4, 404)' );
		$this->assertSame(
			array(
				array(
					'id'        => 1,
					'parent_id' => 1,
					'amount'    => 10,
				),
				array(
					'id'        => 3,
					'parent_id' => 404,
					'amount'    => 20,
				),
			),
			$driver->query( 'SELECT id, parent_id, amount FROM alter_fk_drop_by_key ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_add_foreign_key_rejects_existing_row_violations_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_validate_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_fk_validate_parent (id) VALUES (1)' );
		$driver->query( 'CREATE TABLE alter_fk_validate_child (id INT, parent_id INT, KEY parent_idx (parent_id))' );
		$driver->query( 'INSERT INTO alter_fk_validate_child (id, parent_id) VALUES (1, 1), (2, 404)' );

		$before = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_validate_child' );
		try {
			$driver->query( 'ALTER TABLE alter_fk_validate_child ADD CONSTRAINT fk_validate_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_validate_parent (id)' );
			$this->fail( 'Expected ADD FOREIGN KEY to reject existing orphan rows before mutation.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'existing rows violate the constraint', $e->getMessage() );
		}

		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_validate_child' ) );
		$driver->query( 'INSERT INTO alter_fk_validate_child (id, parent_id) VALUES (3, 405)' );
	}

	public function test_alter_table_foreign_key_actions_reject_without_metadata_or_rebuild(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_action_guard_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_fk_action_guard_parent (id) VALUES (0), (1)' );
		$driver->query( 'CREATE TABLE alter_fk_action_guard_child (id INT PRIMARY KEY, parent_id INT DEFAULT 0, KEY parent_idx (parent_id))' );
		$driver->query( 'INSERT INTO alter_fk_action_guard_child (id, parent_id) VALUES (1, 1)' );

		foreach (
			array(
				'ALTER TABLE alter_fk_action_guard_child ADD CONSTRAINT fk_action_cascade FOREIGN KEY (parent_id) REFERENCES alter_fk_action_guard_parent (id) ON DELETE CASCADE' => 'ON DELETE CASCADE is not supported',
				'ALTER TABLE alter_fk_action_guard_child ADD CONSTRAINT fk_action_set_null FOREIGN KEY (parent_id) REFERENCES alter_fk_action_guard_parent (id) ON DELETE SET NULL' => 'ON DELETE SET NULL is not supported',
				'ALTER TABLE alter_fk_action_guard_child ADD CONSTRAINT fk_action_set_default FOREIGN KEY (parent_id) REFERENCES alter_fk_action_guard_parent (id) ON DELETE SET DEFAULT' => 'ON DELETE SET DEFAULT is not supported',
				'ALTER TABLE alter_fk_action_guard_child ADD CONSTRAINT fk_action_update_cascade FOREIGN KEY (parent_id) REFERENCES alter_fk_action_guard_parent (id) ON UPDATE CASCADE' => 'ON UPDATE CASCADE is not supported',
			) as $sql => $message
		) {
			$before = array(
				'child'                => $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_action_guard_child' ),
				'parent_rows'          => $driver->query( 'SELECT id FROM alter_fk_action_guard_parent ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
			);

			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported FOREIGN KEY action to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				array(
					'child'                => $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_action_guard_child' ),
					'parent_rows'          => $driver->query( 'SELECT id FROM alter_fk_action_guard_parent ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
					'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
				),
				'Unsupported FOREIGN KEY action mutated schema, metadata, or data for SQL: ' . $sql
			);
		}

		$driver->query( 'INSERT INTO alter_fk_action_guard_child (id, parent_id) VALUES (2, 404)' );
		$this->assertSame(
			array(
				array(
					'id'        => 1,
					'parent_id' => 1,
				),
				array(
					'id'        => 2,
					'parent_id' => 404,
				),
			),
			$driver->query( 'SELECT id, parent_id FROM alter_fk_action_guard_child ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_alter_table_foreign_key_limitations_reject_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_limit_parent (id INT PRIMARY KEY, other_id INT, code INT, UNIQUE KEY code_u (code))' );
		$driver->query( 'INSERT INTO alter_fk_limit_parent (id, other_id, code) VALUES (1, 10, 100)' );
		$driver->query( 'CREATE TABLE alter_fk_limit_child (id INT PRIMARY KEY, parent_id INT, other_id INT, code INT)' );
		$driver->query( 'INSERT INTO alter_fk_limit_child (id, parent_id, other_id, code) VALUES (1, 1, 10, 100)' );
		$driver->query( 'CREATE TEMPORARY TABLE alter_fk_limit_temp (id INT, parent_id INT)' );
		$driver->query( 'INSERT INTO alter_fk_limit_temp (id, parent_id) VALUES (1, 1)' );

		foreach (
			array(
				'ALTER TABLE alter_fk_limit_child ADD CONSTRAINT fk_multi FOREIGN KEY (parent_id, other_id) REFERENCES alter_fk_limit_parent (id, other_id)' => 'Only single-column foreign keys are supported',
				'ALTER TABLE alter_fk_limit_child ADD CONSTRAINT fk_schema FOREIGN KEY (parent_id) REFERENCES wp.alter_fk_limit_parent (id)' => 'Schema-qualified references are not supported',
				'ALTER TABLE alter_fk_limit_child ADD CONSTRAINT fk_cascade FOREIGN KEY (parent_id) REFERENCES alter_fk_limit_parent (id) ON DELETE CASCADE' => 'ON DELETE CASCADE is not supported',
				'ALTER TABLE alter_fk_limit_temp ADD CONSTRAINT fk_temp FOREIGN KEY (parent_id) REFERENCES alter_fk_limit_parent (id)' => 'temporary tables is not supported',
				'ALTER TABLE alter_fk_limit_child ADD COLUMN parent_ref INT REFERENCES alter_fk_limit_parent (id)' => 'Inline REFERENCES constraints are only supported in CREATE TABLE',
				'ALTER TABLE alter_fk_limit_child ADD COLUMN should_not_exist INT DEFAULT 2, ADD CONSTRAINT fk_multi_action FOREIGN KEY (parent_id) REFERENCES alter_fk_limit_parent (id)' => 'ADD/DROP FOREIGN KEY cannot be combined with other ALTER TABLE actions',
			) as $sql => $message
		) {
			$before_child = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_limit_child' );
			$before_temp  = $driver->query( 'SELECT id, parent_id FROM alter_fk_limit_temp ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported ALTER TABLE FOREIGN KEY form to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame( $before_child, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_limit_child' ) );
			$this->assertSame( $before_temp, $driver->query( 'SELECT id, parent_id FROM alter_fk_limit_temp ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ) );
		}

		$this->assertSame(
			0,
			$driver->query( 'ALTER TABLE alter_fk_limit_child ADD CONSTRAINT fk_unique_gap FOREIGN KEY (code) REFERENCES alter_fk_limit_parent (code)' )->rowCount()
		);
		$this->assertSame(
			array(
				array(
					'CONSTRAINT_NAME'        => 'fk_unique_gap',
					'UNIQUE_CONSTRAINT_NAME' => 'code_u',
					'TABLE_NAME'             => 'alter_fk_limit_child',
					'REFERENCED_TABLE_NAME'  => 'alter_fk_limit_parent',
				),
			),
			$driver->query(
				"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp' AND table_name = 'alter_fk_limit_child'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertStringContainsString(
			'CONSTRAINT `fk_unique_gap` FOREIGN KEY (`code`) REFERENCES `alter_fk_limit_parent` (`code`)',
			$driver->query( 'SHOW CREATE TABLE alter_fk_limit_child' )->fetch( PDO::FETCH_ASSOC )['Create Table']
		);

		$driver->query( 'BEGIN' );
		try {
			$driver->query( 'ALTER TABLE alter_fk_limit_child ADD CONSTRAINT fk_tx FOREIGN KEY (parent_id) REFERENCES alter_fk_limit_parent (id)' );
			$this->fail( 'Expected active transaction ADD FOREIGN KEY rebuild rejection.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'ADD/DROP FOREIGN KEY cannot run inside an active DuckDB transaction', $e->getMessage() );
		}
		$driver->query( 'ROLLBACK' );

		$driver->query(
			'CREATE TABLE alter_fk_referenced_child (
				id INT PRIMARY KEY,
				parent_id INT,
				CONSTRAINT fk_referenced_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_limit_parent (id)
			)'
		);
		$driver->query( 'CREATE TABLE alter_fk_referenced_grandchild (id INT, child_id INT, CONSTRAINT fk_grandchild FOREIGN KEY (child_id) REFERENCES alter_fk_referenced_child (id))' );
		$driver->query( 'INSERT INTO alter_fk_referenced_child (id, parent_id) VALUES (1, 1)' );
		$driver->query( 'INSERT INTO alter_fk_referenced_grandchild (id, child_id) VALUES (1, 1)' );
		$before = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_referenced_child' );

		try {
			$driver->query( 'ALTER TABLE alter_fk_referenced_child DROP FOREIGN KEY fk_referenced_parent' );
			$this->fail( 'Expected DROP FOREIGN KEY on referenced parent table to reject before mutation.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'referenced by FOREIGN KEY', $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_referenced_child' ) );
	}

	public function test_alter_table_drop_foreign_key_missing_and_generic_missing_constraint_distinction(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query( 'CREATE TABLE alter_fk_missing_parent (id INT PRIMARY KEY)' );
		$driver->query( 'CREATE TABLE alter_fk_missing_child (id INT, parent_id INT, CONSTRAINT fk_missing_parent FOREIGN KEY (parent_id) REFERENCES alter_fk_missing_parent (id))' );
		$driver->query( 'INSERT INTO alter_fk_missing_parent (id) VALUES (1)' );
		$driver->query( 'INSERT INTO alter_fk_missing_child (id, parent_id) VALUES (1, 1)' );

		$before = $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_missing_child' );
		$this->assertSame( 0, $driver->query( 'ALTER TABLE alter_fk_missing_child DROP FOREIGN KEY missing_fk' )->rowCount() );
		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_missing_child' ) );

		try {
			$driver->query( 'ALTER TABLE alter_fk_missing_child DROP CONSTRAINT missing_fk' );
			$this->fail( 'Expected generic DROP CONSTRAINT missing name to reject.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( "Unknown constraint 'missing_fk'", $e->getMessage() );
		}
		$this->assertSame( $before, $this->alter_table_foreign_key_lifecycle_snapshot( $driver, 'alter_fk_missing_child' ) );
	}

	public function test_unsupported_alter_table_constraint_actions_throw_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE alter_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_parent (id) VALUES (1)' );
		$driver->query(
			'CREATE TABLE alter_constraint_guard (
				id INT,
				parent_id INT,
				`check` INT,
				`constraint` INT,
				`foreign` INT,
				CONSTRAINT existing_check CHECK (id >= 0),
				CONSTRAINT existing_fk FOREIGN KEY (parent_id) REFERENCES alter_parent (id),
				UNIQUE KEY id_unique (id)
			)'
		);
		$driver->query( 'INSERT INTO alter_constraint_guard (id, parent_id, `check`, `constraint`, `foreign`) VALUES (1, 1, 7, 8, 9)' );

		$before = $this->alter_table_constraint_guard_snapshot( $driver );

		foreach (
			array(
				'ALTER TABLE alter_constraint_guard ADD COLUMN score INT CHECK (score >= 0)' => 'Inline CHECK constraints are only supported in CREATE TABLE',
				'ALTER TABLE alter_constraint_guard ADD COLUMN parent_ref INT REFERENCES alter_parent (id)' => 'Inline REFERENCES constraints are only supported in CREATE TABLE',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported ALTER TABLE constraint action to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_constraint_guard_snapshot( $driver ),
				'ALTER TABLE constraint rejection mutated schema or data for SQL: ' . $sql
			);
		}

		foreach (
			array(
				'ALTER TABLE alter_constraint_guard DROP CHECK'       => 'DuckDB driver could not parse MySQL statement',
				'ALTER TABLE alter_constraint_guard DROP CONSTRAINT'  => 'DuckDB driver could not parse MySQL statement',
				'ALTER TABLE alter_constraint_guard DROP FOREIGN KEY' => 'Expected a MySQL identifier',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected malformed ALTER TABLE constraint action to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_constraint_guard_snapshot( $driver ),
				'Malformed ALTER TABLE constraint rejection mutated schema or data for SQL: ' . $sql
			);
		}
	}

	public function test_unsupported_alter_table_constraint_actions_in_multi_action_statements_throw_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE alter_parent (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO alter_parent (id) VALUES (1)' );
		$driver->query(
			'CREATE TABLE alter_constraint_guard (
				id INT,
				parent_id INT,
				`check` INT,
				`constraint` INT,
				`foreign` INT,
				CONSTRAINT existing_check CHECK (id >= 0),
				CONSTRAINT existing_fk FOREIGN KEY (parent_id) REFERENCES alter_parent (id),
				UNIQUE KEY id_unique (id)
			)'
		);
		$driver->query( 'INSERT INTO alter_constraint_guard (id, parent_id, `check`, `constraint`, `foreign`) VALUES (1, 1, 7, 8, 9)' );

		$before = $this->alter_table_constraint_guard_snapshot( $driver );

		foreach (
			array(
				'ALTER TABLE alter_constraint_guard ADD CHECK (id >= 0), ADD CONSTRAINT added_fk FOREIGN KEY (parent_id) REFERENCES alter_parent (id)' => 'ADD/DROP CHECK cannot be combined with other ALTER TABLE actions',
				'ALTER TABLE alter_constraint_guard DROP FOREIGN KEY existing_fk, ADD COLUMN should_not_exist INT DEFAULT 2' => 'ADD/DROP FOREIGN KEY cannot be combined with other ALTER TABLE actions',
				'ALTER TABLE alter_constraint_guard ADD COLUMN should_not_exist INT DEFAULT 2, ADD CONSTRAINT added_fk FOREIGN KEY (parent_id) REFERENCES alter_parent (id)' => 'ADD/DROP FOREIGN KEY cannot be combined with other ALTER TABLE actions',
				'ALTER TABLE alter_constraint_guard ADD COLUMN should_not_exist INT DEFAULT 2, ADD CONSTRAINT added_unique UNIQUE KEY (id)' => 'ADD UNIQUE constraint cannot be combined with other ALTER TABLE actions',
				'ALTER TABLE alter_constraint_guard ADD COLUMN should_not_exist INT DEFAULT 2, DROP CONSTRAINT existing_fk' => 'ADD/DROP FOREIGN KEY cannot be combined with other ALTER TABLE actions',
				'ALTER TABLE alter_constraint_guard ADD COLUMN should_not_exist INT DEFAULT 2, ADD COLUMN inline_check INT CHECK (inline_check >= 0)' => 'Inline CHECK constraints are only supported in CREATE TABLE',
				'ALTER TABLE alter_constraint_guard ADD COLUMN should_not_exist INT DEFAULT 2, ADD COLUMN inline_parent INT REFERENCES alter_parent (id)' => 'Inline REFERENCES constraints are only supported in CREATE TABLE',
			) as $sql => $message
		) {
			try {
				$driver->query( $sql );
				$this->fail( 'Expected multi-action ALTER TABLE constraint action to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				$this->alter_table_constraint_guard_snapshot( $driver ),
				'Multi-action ALTER TABLE constraint rejection mutated schema or data for SQL: ' . $sql
			);
		}
	}

	public function test_unsupported_create_table_foreign_key_actions_throw_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY)' );
		$driver->query( 'INSERT INTO parents (id) VALUES (1)' );

		foreach (
			array(
				'CREATE TABLE child_cascade (parent_id INT, FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE)' => 'ON DELETE CASCADE is not supported',
				'CREATE TABLE child_update_cascade (parent_id INT, FOREIGN KEY (parent_id) REFERENCES parents (id) ON UPDATE CASCADE)' => 'ON UPDATE CASCADE is not supported',
				'CREATE TABLE child_set_null (parent_id INT, FOREIGN KEY (parent_id) REFERENCES parents (id) ON UPDATE SET NULL)' => 'ON UPDATE SET NULL is not supported',
				'CREATE TABLE child_set_default (parent_id INT DEFAULT 0, FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE SET DEFAULT)' => 'ON DELETE SET DEFAULT is not supported',
				'CREATE TABLE child_inline_cascade (parent_id INT REFERENCES parents (id) ON DELETE CASCADE)' => 'ON DELETE CASCADE is not supported',
				'CREATE TABLE child_inline_set_null (parent_id INT REFERENCES parents (id) ON UPDATE SET NULL)' => 'ON UPDATE SET NULL is not supported',
				'CREATE TABLE child_inline_set_default (parent_id INT DEFAULT 0 REFERENCES parents (id) ON DELETE SET DEFAULT)' => 'ON DELETE SET DEFAULT is not supported',
			) as $sql => $message
		) {
			$before = array(
				'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
				'parent_rows'          => $driver->query( 'SELECT id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
			);

			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported FOREIGN KEY action to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				array(
					'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
					'parent_rows'          => $driver->query( 'SELECT id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
					'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
				),
				'Unsupported FOREIGN KEY action created schema, metadata, or data for SQL: ' . $sql
			);
		}

		$this->assertSame(
			array( array( 'Tables_in_wp' => 'parents' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'id' => 1 ) ),
			$driver->query( 'SELECT id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_unsupported_table_level_foreign_key_shapes_throw_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY, other_id INT)' );
		$driver->query( 'INSERT INTO parents (id, other_id) VALUES (1, 10)' );

		foreach (
			array(
				'CREATE TABLE child_schema_fk (
					parent_id INT,
					CONSTRAINT fk_schema FOREIGN KEY (parent_id) REFERENCES wp.parents (id)
				)' => 'Schema-qualified references are not supported',
				'CREATE TABLE child_composite_fk (
					parent_id INT,
					other_id INT,
					CONSTRAINT fk_composite FOREIGN KEY (parent_id, other_id) REFERENCES parents (id, other_id)
				)' => 'Only single-column foreign keys are supported',
				'CREATE TABLE child_composite_ref_fk (
					parent_id INT,
					CONSTRAINT fk_composite_ref FOREIGN KEY (parent_id) REFERENCES parents (id, other_id)
				)' => 'Only single-column foreign keys are supported',
				'CREATE TABLE child_missing_ref_list (
					parent_id INT,
					CONSTRAINT fk_missing_ref FOREIGN KEY (parent_id) REFERENCES parents
				)' => 'Expected FOREIGN KEY referenced column list',
			) as $sql => $message
		) {
			$before = array(
				'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
				'parent_rows'          => $driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
			);

			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported table-level FOREIGN KEY shape to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				array(
					'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
					'parent_rows'          => $driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
					'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
				),
				'Unsupported table-level FOREIGN KEY shape created schema, metadata, or data for SQL: ' . $sql
			);
		}

		$this->assertSame(
			array( array( 'Tables_in_wp' => 'parents' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'other_id' => 10,
				),
			),
			$driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_duckdb_connection_usable( $driver );
	}

	public function test_unsupported_inline_references_shapes_throw_before_mutation(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver( array( 'path' => ':memory:' ) );
		$driver->query( 'CREATE TABLE parents (id INT PRIMARY KEY, other_id INT)' );
		$driver->query( 'INSERT INTO parents (id, other_id) VALUES (1, 10)' );

		foreach (
			array(
				'CREATE TABLE child_inline_schema (parent_id INT REFERENCES wp.parents (id))' => 'Schema-qualified references are not supported',
				'CREATE TABLE child_inline_composite (parent_id INT REFERENCES parents (id, other_id))' => 'Only single-column foreign keys are supported',
				'CREATE TABLE child_inline_missing_list (parent_id INT REFERENCES parents)' => 'Expected FOREIGN KEY referenced column list',
			) as $sql => $message
		) {
			$before = array(
				'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
				'parent_rows'          => $driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
				'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
			);

			try {
				$driver->query( $sql );
				$this->fail( 'Expected unsupported inline REFERENCES shape to reject SQL: ' . $sql );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( $message, $e->getMessage() );
			}

			$this->assertSame(
				$before,
				array(
					'tables'               => $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC ),
					'parent_rows'          => $driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
					'foreign_key_metadata' => $this->foreign_key_rejection_metadata_snapshot( $driver ),
				),
				'Unsupported inline REFERENCES shape created schema, metadata, or data for SQL: ' . $sql
			);
		}

		$this->assertSame(
			array( array( 'Tables_in_wp' => 'parents' ) ),
			$driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'other_id' => 10,
				),
			),
			$driver->query( 'SELECT id, other_id FROM parents ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_duckdb_connection_usable( $driver );
	}

	public function test_unsupported_alter_table_add_auto_increment_throws_driver_exception(): void {
		$this->requireDuckDBRuntime();

		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);
		$driver->query(
			'CREATE TABLE add_column_auto_guard (
				name VARCHAR(100) NOT NULL,
				slug VARCHAR(100),
				UNIQUE KEY name_unique (name),
				KEY slug_idx (slug)
			)'
		);
		$driver->query( "INSERT INTO add_column_auto_guard (name, slug) VALUES ('Ada', 'ada'), ('Grace', 'grace')" );

		$before = $this->alter_table_inline_constraint_guard_snapshot( $driver, 'add_column_auto_guard' );
		$this->assertDriverQueryRejected(
			$driver,
			'ALTER TABLE add_column_auto_guard ADD COLUMN id BIGINT AUTO_INCREMENT',
			'ADD COLUMN AUTO_INCREMENT is not supported'
		);

		$this->assertSame( $before, $this->alter_table_inline_constraint_guard_snapshot( $driver, 'add_column_auto_guard' ) );
		$this->assert_duckdb_connection_usable( $driver );
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

	private function createMetadataTypeMatrixTable( WP_DuckDB_Driver $driver ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$driver->query(
			"CREATE TABLE metadata_type_matrix (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				flag TINYINT UNSIGNED NOT NULL DEFAULT '10',
				small_code SMALLINT NOT NULL DEFAULT 14,
				medium_code MEDIUMINT,
				count_col INT UNSIGNED,
				score DOUBLE,
				price DECIMAL(10,2) DEFAULT 1.25,
				slug CHAR(10),
				title VARCHAR(20) NOT NULL DEFAULT 'untitled',
				body LONGTEXT,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at TIMESTAMP NULL,
				payload BLOB
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
	}

	private function metadataTypeMatrixResultMetadata(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'native_type'      => 'LONGLONG',
				'table'            => 'metadata_type_matrix',
				'name'             => 'id',
				'len'              => 20,
				'precision'        => 0,
				'duckdb:decl_type' => 'bigint(20) unsigned',
				'mysqli:orgname'   => 'id',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 8,
			),
			array(
				'native_type'      => 'TINY',
				'table'            => 'metadata_type_matrix',
				'name'             => 'flag',
				'len'              => 3,
				'precision'        => 0,
				'duckdb:decl_type' => 'tinyint unsigned',
				'mysqli:orgname'   => 'flag',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 1,
			),
			array(
				'native_type'      => 'SHORT',
				'table'            => 'metadata_type_matrix',
				'name'             => 'small_code',
				'len'              => 6,
				'precision'        => 0,
				'duckdb:decl_type' => 'smallint',
				'mysqli:orgname'   => 'small_code',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 2,
			),
			array(
				'native_type'      => 'INT24',
				'table'            => 'metadata_type_matrix',
				'name'             => 'medium_code',
				'len'              => 9,
				'precision'        => 0,
				'duckdb:decl_type' => 'mediumint',
				'mysqli:orgname'   => 'medium_code',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 9,
			),
			array(
				'native_type'      => 'LONG',
				'table'            => 'metadata_type_matrix',
				'name'             => 'count_col',
				'len'              => 10,
				'precision'        => 0,
				'duckdb:decl_type' => 'int unsigned',
				'mysqli:orgname'   => 'count_col',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 3,
			),
			array(
				'native_type'      => 'DOUBLE',
				'table'            => 'metadata_type_matrix',
				'name'             => 'score',
				'len'              => 22,
				'precision'        => 31,
				'duckdb:decl_type' => 'double',
				'mysqli:orgname'   => 'score',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 5,
			),
			array(
				'native_type'      => 'NEWDECIMAL',
				'table'            => 'metadata_type_matrix',
				'name'             => 'price',
				'len'              => 12,
				'precision'        => 2,
				'duckdb:decl_type' => 'decimal(10,2)',
				'mysqli:orgname'   => 'price',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 246,
			),
			array(
				'native_type'      => 'STRING',
				'table'            => 'metadata_type_matrix',
				'name'             => 'slug',
				'len'              => 40,
				'precision'        => 0,
				'duckdb:decl_type' => 'char(10)',
				'mysqli:orgname'   => 'slug',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 254,
			),
			array(
				'native_type'      => 'VAR_STRING',
				'table'            => 'metadata_type_matrix',
				'name'             => 'title',
				'len'              => 80,
				'precision'        => 0,
				'duckdb:decl_type' => 'varchar(20)',
				'mysqli:orgname'   => 'title',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 253,
			),
			array(
				'native_type'      => 'BLOB',
				'table'            => 'metadata_type_matrix',
				'name'             => 'body',
				'len'              => 4294967295,
				'precision'        => 0,
				'duckdb:decl_type' => 'longtext',
				'mysqli:orgname'   => 'body',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 255,
				'mysqli:type'      => 252,
			),
			array(
				'native_type'      => 'DATETIME',
				'table'            => 'metadata_type_matrix',
				'name'             => 'created_at',
				'len'              => 19,
				'precision'        => 0,
				'duckdb:decl_type' => 'datetime',
				'mysqli:orgname'   => 'created_at',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 12,
			),
			array(
				'native_type'      => 'TIMESTAMP',
				'table'            => 'metadata_type_matrix',
				'name'             => 'updated_at',
				'len'              => 19,
				'precision'        => 0,
				'duckdb:decl_type' => 'timestamp',
				'mysqli:orgname'   => 'updated_at',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 7,
			),
			array(
				'native_type'      => 'BLOB',
				'table'            => 'metadata_type_matrix',
				'name'             => 'payload',
				'len'              => 65535,
				'precision'        => 0,
				'duckdb:decl_type' => 'blob',
				'mysqli:orgname'   => 'payload',
				'mysqli:orgtable'  => 'metadata_type_matrix',
				'mysqli:db'        => 'wp',
				'mysqli:charsetnr' => 63,
				'mysqli:type'      => 252,
			),
		);
	}

	private function metadataTypeMatrixShowColumnRows(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'Field'   => 'id',
				'Type'    => 'bigint(20) unsigned',
				'Null'    => 'NO',
				'Key'     => 'PRI',
				'Default' => null,
				'Extra'   => 'auto_increment',
			),
			array(
				'Field'   => 'flag',
				'Type'    => 'tinyint unsigned',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '10',
				'Extra'   => '',
			),
			array(
				'Field'   => 'small_code',
				'Type'    => 'smallint',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '14',
				'Extra'   => '',
			),
			array(
				'Field'   => 'medium_code',
				'Type'    => 'mediumint',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'count_col',
				'Type'    => 'int unsigned',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'score',
				'Type'    => 'double',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'price',
				'Type'    => 'decimal(10,2)',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => '1.25',
				'Extra'   => '',
			),
			array(
				'Field'   => 'slug',
				'Type'    => 'char(10)',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'title',
				'Type'    => 'varchar(20)',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => 'untitled',
				'Extra'   => '',
			),
			array(
				'Field'   => 'body',
				'Type'    => 'longtext',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'created_at',
				'Type'    => 'datetime',
				'Null'    => 'NO',
				'Key'     => '',
				'Default' => '0000-00-00 00:00:00',
				'Extra'   => '',
			),
			array(
				'Field'   => 'updated_at',
				'Type'    => 'timestamp',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
			array(
				'Field'   => 'payload',
				'Type'    => 'blob',
				'Null'    => 'YES',
				'Key'     => '',
				'Default' => null,
				'Extra'   => '',
			),
		);
	}

	private function metadataTypeMatrixShowFullColumnRows(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$collations = array(
			'id'          => null,
			'flag'        => null,
			'small_code'  => null,
			'medium_code' => null,
			'count_col'   => null,
			'score'       => null,
			'price'       => null,
			'slug'        => 'utf8mb4_0900_ai_ci',
			'title'       => 'utf8mb4_0900_ai_ci',
			'body'        => 'utf8mb4_0900_ai_ci',
			'created_at'  => null,
			'updated_at'  => null,
			'payload'     => null,
		);
		$rows       = array();

		foreach ( $this->metadataTypeMatrixShowColumnRows() as $row ) {
			$rows[] = array(
				'Field'      => $row['Field'],
				'Type'       => $row['Type'],
				'Collation'  => $collations[ $row['Field'] ],
				'Null'       => $row['Null'],
				'Key'        => $row['Key'],
				'Default'    => $row['Default'],
				'Extra'      => $row['Extra'],
				'Privileges' => 'select,insert,update,references',
				'Comment'    => '',
			);
		}

		return $rows;
	}

	private function metadataTypeMatrixInformationSchemaRows(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			array(
				'COLUMN_NAME'              => 'id',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'NO',
				'DATA_TYPE'                => 'bigint',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 20,
				'NUMERIC_SCALE'            => 0,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'bigint(20) unsigned',
				'COLUMN_KEY'               => 'PRI',
				'EXTRA'                    => 'auto_increment',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'flag',
				'COLUMN_DEFAULT'           => '10',
				'IS_NULLABLE'              => 'NO',
				'DATA_TYPE'                => 'tinyint',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 3,
				'NUMERIC_SCALE'            => 0,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'tinyint unsigned',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'small_code',
				'COLUMN_DEFAULT'           => '14',
				'IS_NULLABLE'              => 'NO',
				'DATA_TYPE'                => 'smallint',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 5,
				'NUMERIC_SCALE'            => 0,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'smallint',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'medium_code',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'mediumint',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 7,
				'NUMERIC_SCALE'            => 0,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'mediumint',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'count_col',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'int',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 10,
				'NUMERIC_SCALE'            => 0,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'int unsigned',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'score',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'double',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 22,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'double',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'price',
				'COLUMN_DEFAULT'           => '1.25',
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'decimal',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => 10,
				'NUMERIC_SCALE'            => 2,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'decimal(10,2)',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'slug',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'char',
				'CHARACTER_MAXIMUM_LENGTH' => 10,
				'CHARACTER_OCTET_LENGTH'   => 40,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => 'utf8mb4',
				'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
				'COLUMN_TYPE'              => 'char(10)',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'title',
				'COLUMN_DEFAULT'           => 'untitled',
				'IS_NULLABLE'              => 'NO',
				'DATA_TYPE'                => 'varchar',
				'CHARACTER_MAXIMUM_LENGTH' => 20,
				'CHARACTER_OCTET_LENGTH'   => 80,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => 'utf8mb4',
				'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
				'COLUMN_TYPE'              => 'varchar(20)',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'body',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'longtext',
				'CHARACTER_MAXIMUM_LENGTH' => 4294967295,
				'CHARACTER_OCTET_LENGTH'   => 4294967295,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => 'utf8mb4',
				'COLLATION_NAME'           => 'utf8mb4_0900_ai_ci',
				'COLUMN_TYPE'              => 'longtext',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'created_at',
				'COLUMN_DEFAULT'           => '0000-00-00 00:00:00',
				'IS_NULLABLE'              => 'NO',
				'DATA_TYPE'                => 'datetime',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => 0,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'datetime',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'updated_at',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'timestamp',
				'CHARACTER_MAXIMUM_LENGTH' => null,
				'CHARACTER_OCTET_LENGTH'   => null,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => 0,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'timestamp',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
			array(
				'COLUMN_NAME'              => 'payload',
				'COLUMN_DEFAULT'           => null,
				'IS_NULLABLE'              => 'YES',
				'DATA_TYPE'                => 'blob',
				'CHARACTER_MAXIMUM_LENGTH' => 65535,
				'CHARACTER_OCTET_LENGTH'   => 65535,
				'NUMERIC_PRECISION'        => null,
				'NUMERIC_SCALE'            => null,
				'DATETIME_PRECISION'       => null,
				'CHARACTER_SET_NAME'       => null,
				'COLLATION_NAME'           => null,
				'COLUMN_TYPE'              => 'blob',
				'COLUMN_KEY'               => '',
				'EXTRA'                    => '',
				'COLUMN_COMMENT'           => '',
			),
		);
	}

	private function alter_table_auto_increment_snapshot( WP_DuckDB_Driver $driver, string $table_name ): array {
		return array(
			'rows'           => $driver->query( 'SELECT * FROM ' . $table_name . ' ORDER BY 1' )->fetchAll( PDO::FETCH_ASSOC ),
			'show_create'    => $driver->query( 'SHOW CREATE TABLE ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'auto_increment' => $driver->query(
				"SELECT `AUTO_INCREMENT`
				FROM information_schema.tables
				WHERE table_schema = 'wp' AND table_name = '{$table_name}'"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'status'         => $driver->query( "SHOW TABLE STATUS LIKE '{$table_name}'" )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function alter_table_inline_constraint_guard_snapshot( WP_DuckDB_Driver $driver, string $table_name ): array {
		$snapshot                   = $this->alter_table_unique_constraint_snapshot( $driver, $table_name );
		$snapshot['auto_increment'] = $driver->query(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = '{$table_name}'"
		)->fetchAll( PDO::FETCH_ASSOC );
		$snapshot['status']         = $driver->query( "SHOW TABLE STATUS LIKE '{$table_name}'" )->fetchAll( PDO::FETCH_ASSOC );

		return $snapshot;
	}

	private function alter_table_foreign_key_lifecycle_snapshot( WP_DuckDB_Driver $driver, string $table_name ): array {
		return array(
			'columns'                 => $driver->query( 'SHOW COLUMNS FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'rows'                    => $driver->query( 'SELECT * FROM ' . $table_name . ' ORDER BY 1' )->fetchAll( PDO::FETCH_ASSOC ),
			'indexes'                 => $driver->query( 'SHOW INDEX FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'table_constraints'       => $driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = '{$table_name}'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'referential_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp' AND table_name = '{$table_name}'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'key_column_usage'        => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name = '{$table_name}'
					AND referenced_table_name IS NOT NULL
				ORDER BY constraint_name, ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'show_create'             => $driver->query( 'SHOW CREATE TABLE ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function alter_table_constraint_guard_snapshot( WP_DuckDB_Driver $driver ): array {
		return array(
			'columns'                 => array_column( $driver->query( 'SHOW COLUMNS FROM alter_constraint_guard' )->fetchAll( PDO::FETCH_ASSOC ), 'Field' ),
			'rows'                    => $driver->query( 'SELECT id, parent_id, `check`, `constraint`, `foreign` FROM alter_constraint_guard' )->fetchAll( PDO::FETCH_ASSOC ),
			'table_constraints'       => $driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = 'alter_constraint_guard'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'check_constraints'       => $driver->query(
				"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
				FROM information_schema.check_constraints
				WHERE constraint_schema = 'wp' AND constraint_name = 'existing_check'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'referential_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp' AND table_name = 'alter_constraint_guard'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'show_create'             => $driver->query( 'SHOW CREATE TABLE alter_constraint_guard' )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function foreign_key_rejection_metadata_snapshot( WP_DuckDB_Driver $driver ): array {
		return array(
			'table_constraints'       => $driver->query(
				"SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp'
					AND constraint_type = 'FOREIGN KEY'
				ORDER BY table_name, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'referential_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp'
				ORDER BY table_name, constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'key_column_usage'        => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND referenced_table_name IS NOT NULL
				ORDER BY table_name, constraint_name, ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function alter_table_unique_constraint_snapshot( WP_DuckDB_Driver $driver, string $table_name ): array {
		return array(
			'columns'           => $driver->query( 'SHOW COLUMNS FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'rows'              => $driver->query( 'SELECT * FROM ' . $table_name . ' ORDER BY 1' )->fetchAll( PDO::FETCH_ASSOC ),
			'indexes'           => $driver->query( 'SHOW INDEX FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'table_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = '{$table_name}'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'statistics'        => $driver->query(
				"SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
				FROM information_schema.statistics
				WHERE table_schema = 'wp' AND table_name = '{$table_name}'
				ORDER BY index_name, seq_in_index"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'key_column_usage'  => $driver->query(
				"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
				FROM information_schema.key_column_usage
				WHERE table_schema = 'wp'
					AND table_name = '{$table_name}'
					AND referenced_table_name IS NULL
				ORDER BY constraint_name, ordinal_position"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'show_create'       => $driver->query( 'SHOW CREATE TABLE ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function alter_table_check_lifecycle_snapshot( WP_DuckDB_Driver $driver, string $table_name ): array {
		return array(
			'columns'           => $driver->query( 'SHOW COLUMNS FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'rows'              => $driver->query( 'SELECT * FROM ' . $table_name . ' ORDER BY 1' )->fetchAll( PDO::FETCH_ASSOC ),
			'indexes'           => $driver->query( 'SHOW INDEX FROM ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
			'table_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
				FROM information_schema.table_constraints
				WHERE table_schema = 'wp' AND table_name = '{$table_name}'
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'check_constraints' => $driver->query(
				"SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
				FROM information_schema.table_constraints AS tc
				JOIN information_schema.check_constraints AS cc
					ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
					AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
				WHERE tc.table_schema = 'wp' AND tc.table_name = '{$table_name}'
				ORDER BY tc.constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'show_create'       => $driver->query( 'SHOW CREATE TABLE ' . $table_name )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function alter_table_referenced_parent_snapshot( WP_DuckDB_Driver $driver ): array {
		return array(
			'parent'                  => $this->alter_table_check_lifecycle_snapshot( $driver, 'alter_check_parent_guard' ),
			'child_rows'              => $driver->query( 'SELECT id, parent_id FROM alter_check_child_guard ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC ),
			'referential_constraints' => $driver->query(
				"SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
				FROM information_schema.referential_constraints
				WHERE constraint_schema = 'wp'
					AND (table_name = 'alter_check_child_guard' OR referenced_table_name = 'alter_check_parent_guard')
				ORDER BY constraint_name"
			)->fetchAll( PDO::FETCH_ASSOC ),
			'child_show_create'       => $driver->query( 'SHOW CREATE TABLE alter_check_child_guard' )->fetchAll( PDO::FETCH_ASSOC ),
		);
	}

	private function assert_empty_statement_result( WP_DuckDB_Result_Statement $result, string $sql ): void {
		$this->assertSame( 0, $result->rowCount(), 'Row count mismatch for SQL: ' . $sql );
		$this->assertSame( 0, $result->columnCount(), 'Column count mismatch for SQL: ' . $sql );
		$this->assertFalse( $result->getColumnMeta( 0 ), 'Column metadata mismatch for SQL: ' . $sql );
		$this->assertFalse( $result->fetch( PDO::FETCH_ASSOC ), 'Fetch mismatch for SQL: ' . $sql );
		$this->assertSame( array(), $result->fetchAll( PDO::FETCH_ASSOC ), 'Fetch-all mismatch for SQL: ' . $sql );
	}

	private function assertDriverQueryRejected( WP_DuckDB_Driver $driver, string $sql, string $message_substring = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		try {
			$driver->query( $sql );
			$this->fail( 'Expected DuckDB driver rejection for SQL: ' . $sql );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			if ( '' !== $message_substring ) {
				$this->assertStringContainsString( $message_substring, $e->getMessage() );
			}
		}
	}

	private function assert_duckdb_connection_usable( WP_DuckDB_Driver $driver ): void {
		$this->assertSame(
			array(
				array(
					'still_usable' => 1,
				),
			),
			$driver->query( 'SELECT 1 AS still_usable' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	private function lastDuckDBQuery( WP_DuckDB_Driver $driver ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$queries = $driver->get_last_duckdb_queries();
		return $queries[ count( $queries ) - 1 ];
	}

	private function mysql_single_quoted_literal( string $value ): string {
		$backslash = chr( 92 );

		return "'" . strtr(
			$value,
			array(
				$backslash => $backslash . $backslash,
				chr( 0 )   => $backslash . '0',
				chr( 10 )  => $backslash . 'n',
				chr( 13 )  => $backslash . 'r',
				chr( 26 )  => $backslash . 'Z',
				"'"        => $backslash . "'",
				'"'        => $backslash . '"',
			)
		) . "'";
	}

	private function query_logged_duckdb_driver( array &$queries, string $path = ':memory:' ): WP_DuckDB_Driver {
		$connection = new WP_DuckDB_Connection( array( 'path' => $path ) );
		$connection->set_query_logger(
			function ( string $sql, array $params ) use ( &$queries ): void {
				unset( $params );
				$queries[] = $sql;
			}
		);

		return new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
	}

	private function create_wordpress_options_autoload_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			'CREATE TABLE wptests_options (
				option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				option_name VARCHAR(191) NOT NULL DEFAULT \'\',
				option_value LONGTEXT NOT NULL,
				autoload VARCHAR(20) NOT NULL DEFAULT \'yes\',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload) VALUES
				('siteurl', 'https://example.test', 'yes'),
				('home', 'https://example.test', 'on'),
				('blogname', 'Example', 'auto-on'),
				('transient', 'nope', 'no')"
		);
	}

	private function create_wordpress_posts_id_lookup_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_title TEXT NOT NULL,
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_author, post_date, post_title, post_type) VALUES
				(1, 10, '2026-01-01 00:00:00', 'First', 'post'),
				(2, 20, '2026-02-01 00:00:00', 'Second', 'page')"
		);
	}

	private function create_wordpress_posts_slug_status_lookup_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_title TEXT NOT NULL,
				post_name VARCHAR(200) NOT NULL DEFAULT '',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID),
				KEY post_name (post_name(191)),
				KEY type_status_date (post_type, post_status, post_date, ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_author, post_date, post_title, post_name, post_type, post_status) VALUES
				(1, 10, '2026-01-01 00:00:00', 'Old page', 'target-slug', 'page', 'publish'),
				(2, 20, '2026-05-01 00:00:00', 'Excluded page', 'target-slug', 'page', 'publish'),
				(3, 30, '2026-02-01 00:00:00', 'Older post tie', 'target-slug', 'post', 'publish'),
				(4, 40, '2026-04-01 00:00:00', 'Draft post', 'target-slug', 'post', 'draft'),
				(5, 50, '2026-03-01 00:00:00', 'Attachment', 'target-slug', 'attachment', 'publish'),
				(6, 60, '2026-02-01 00:00:00', 'Newer post tie', 'target-slug', 'post', 'publish'),
				(7, 70, '2026-06-01 00:00:00', 'Wrong type', 'target-slug', 'product', 'publish'),
				(8, 80, '2026-07-01 00:00:00', 'Wrong slug', 'other-slug', 'post', 'publish')"
		);
	}

	private function create_wordpress_usermeta_cache_load_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_usermeta (
				umeta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES
				(1, 1, 'first_name', 'Alice'),
				(2, 2, 'first_name', 'Bob'),
				(3, 1, 'last_name', 'Adams'),
				(4, 3, 'first_name', 'Cara')"
		);
	}

	private function create_wordpress_meta_cache_load_fixture( WP_DuckDB_Driver $driver, string $table_name, string $object_column ): void {
		$driver->query(
			"CREATE TABLE {$table_name} (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				{$object_column} BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY object_id ({$object_column}),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO {$table_name} (meta_id, {$object_column}, meta_key, meta_value) VALUES
				(1, 1, 'first_name', '{$table_name}-Alice'),
				(2, 2, 'first_name', '{$table_name}-Bob'),
				(3, 1, 'last_name', '{$table_name}-Adams'),
				(4, 3, 'first_name', '{$table_name}-Cara')"
		);
	}

	private function create_wordpress_statistics_projection_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_termmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY term_id (term_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_commentmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				comment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY comment_id (comment_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
	}

	private function create_wordpress_constraint_projection_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_constraints_target (
				site_id BIGINT(20) UNSIGNED NOT NULL,
				option_id BIGINT(20) UNSIGNED NOT NULL,
				option_name VARCHAR(191) NOT NULL DEFAULT '',
				payload LONGTEXT,
				PRIMARY KEY (site_id, option_id),
				UNIQUE KEY unique_site_option (site_id, option_name),
				KEY payload_prefix (payload(12))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_constraints_noise (
				noise_id BIGINT(20) UNSIGNED NOT NULL,
				slug VARCHAR(191) NOT NULL DEFAULT '',
				marker VARCHAR(20),
				PRIMARY KEY (noise_id),
				UNIQUE KEY slug_unique (slug),
				KEY marker_key (marker)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query( 'CREATE TABLE wptests_constraints_empty (id INT, note TEXT)' );
	}

	private function create_wordpress_posts_auto_increment_insert_fixture( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_title TEXT NOT NULL,
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID),
				KEY type_status_date (post_type, post_status, post_date, ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
	}

	private function create_wordpress_usermeta_auto_increment_insert_fixture( WP_DuckDB_Driver $driver, string $table_name ): void {
		$driver->query(
			'CREATE TABLE ' . $table_name . ' (
				umeta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
	}

	private function wordpress_options_autoload_select_sql(): string {
		return "SELECT option_name, option_value FROM wptests_options WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')";
	}

	private function wordpress_usermeta_cache_load_select_sql( string $id_list = '1', string $table_name = 'wptests_usermeta' ): string {
		return 'SELECT user_id, meta_key, meta_value FROM ' . $table_name . ' WHERE user_id IN (' . $id_list . ') ORDER BY umeta_id ASC';
	}

	private function wordpress_meta_cache_load_select_sql( string $object_column, string $id_list, string $table_name, string $order_column ): string {
		return 'SELECT ' . $object_column . ', meta_key, meta_value FROM ' . $table_name . ' WHERE ' . $object_column . ' IN (' . $id_list . ') ORDER BY ' . $order_column . ' ASC';
	}

	private function wordpress_non_user_meta_cache_load_cases(): array {
		return array(
			array(
				'table'         => 'wptests_postmeta',
				'object_column' => 'post_id',
				'order_column'  => 'meta_id',
			),
			array(
				'table'         => 'wptests_termmeta',
				'object_column' => 'term_id',
				'order_column'  => 'meta_id',
			),
			array(
				'table'         => 'wptests_commentmeta',
				'object_column' => 'comment_id',
				'order_column'  => 'meta_id',
			),
		);
	}

	private function wordpress_meta_cache_load_expected_rows( string $table_name, string $object_column ): array {
		return array(
			array(
				$object_column => 1,
				'meta_key'     => 'first_name',
				'meta_value'   => $table_name . '-Alice',
			),
			array(
				$object_column => 2,
				'meta_key'     => 'first_name',
				'meta_value'   => $table_name . '-Bob',
			),
			array(
				$object_column => 1,
				'meta_key'     => 'last_name',
				'meta_value'   => $table_name . '-Adams',
			),
		);
	}

	private function wordpress_posts_slug_status_lookup_select_sql(): string {
		return "SELECT wptests_posts.* FROM wptests_posts
			WHERE 1=1
				AND wptests_posts.post_name = 'target-slug'
				AND wptests_posts.ID NOT IN (2)
				AND wptests_posts.post_type IN ('post', 'page', 'attachment')
				AND ((wptests_posts.post_status = 'publish'))
			ORDER BY wptests_posts.post_date DESC";
	}

	private function wordpress_term_relationships_distinct_terms_select_sql(): string {
		return "SELECT DISTINCT t.term_id
			FROM wptests_terms AS t
				INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				INNER JOIN wptests_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND tr.object_id IN (201, 202)
			ORDER BY t.name ASC";
	}

	private function assert_wordpress_options_autoload_rows( WP_DuckDB_Driver $driver ): void {
		$result = $driver->query( $this->wordpress_options_autoload_select_sql() );
		$this->assert_wordpress_options_autoload_result( $result );
	}

	private function assert_wordpress_options_autoload_result( WP_DuckDB_Result_Statement $result ): void {
		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Example',
				),
				array(
					'option_name'  => 'home',
					'option_value' => 'https://example.test',
				),
				array(
					'option_name'  => 'siteurl',
					'option_value' => 'https://example.test',
				),
			),
			$this->sorted_wordpress_options_autoload_rows( $result )
		);

		$option_name_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'option_name', $option_name_meta['name'] );
		$this->assertSame( 'wptests_options', $option_name_meta['table'] );
		$this->assertSame( 'option_name', $option_name_meta['mysqli:orgname'] );
		$this->assertSame( 'wptests_options', $option_name_meta['mysqli:orgtable'] );
		$this->assertSame( 'wp', $option_name_meta['mysqli:db'] );

		$option_value_meta = $result->getColumnMeta( 1 );
		$this->assertSame( 'option_value', $option_value_meta['name'] );
		$this->assertSame( 'wptests_options', $option_value_meta['table'] );
		$this->assertSame( 'option_value', $option_value_meta['mysqli:orgname'] );
		$this->assertSame( 'wptests_options', $option_value_meta['mysqli:orgtable'] );
		$this->assertSame( 'wp', $option_value_meta['mysqli:db'] );
	}

	private function sorted_wordpress_options_autoload_rows( WP_DuckDB_Result_Statement $result ): array {
		$rows = $result->fetchAll( PDO::FETCH_ASSOC );
		usort(
			$rows,
			function ( array $left, array $right ): int {
				return strcmp( (string) $left['option_name'], (string) $right['option_name'] );
			}
		);

		return $rows;
	}

	private function assert_wordpress_options_autoload_select_used_one_native_query( array $queries ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_options' ) );
		$this->assertStringContainsString( 'wptests_options', $queries[0] );
	}

	private function assert_wordpress_options_single_option_select_used_one_native_query( array $queries ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_options' ) );
		$this->assertStringContainsString( 'wptests_options', $queries[0] );
		$this->assertStringContainsString( 'lower("option_name")', $queries[0] );
	}

	private function assert_wordpress_options_update_used_one_native_query( array $queries ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_options' ) );
		$this->assertStringNotContainsString( '__wp_duckdb_table_metadata', $queries[0] );
		$this->assertStringContainsString( 'UPDATE "wptests_options" SET "option_value" = ', $queries[0] );
		$this->assertStringContainsString( 'lower("option_name")', $queries[0] );
		$this->assertStringContainsString( 'IS DISTINCT FROM', $queries[0] );
	}

	private function assert_wordpress_usermeta_cache_load_select_used_one_native_query( array $queries, string $id_list ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_usermeta' ) );
		$this->assertStringContainsString(
			'SELECT "user_id", "meta_key", "meta_value" FROM "wptests_usermeta"',
			$queries[0]
		);
		$this->assertStringContainsString( 'WHERE "user_id" IN (' . $id_list . ')', $queries[0] );
		$this->assertStringContainsString( 'ORDER BY "umeta_id" ASC', $queries[0] );
	}

	private function assert_wordpress_meta_cache_load_select_used_one_native_query( array $queries, string $table_name, string $object_column, string $order_column, string $id_list ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, $table_name ) );
		$this->assertStringContainsString(
			'SELECT "' . $object_column . '", "meta_key", "meta_value" FROM "' . $table_name . '"',
			$queries[0]
		);
		$this->assertStringContainsString( 'WHERE "' . $object_column . '" IN (' . $id_list . ')', $queries[0] );
		$this->assertStringContainsString( 'ORDER BY "' . $order_column . '" ASC', $queries[0] );
	}

	private function assert_wordpress_usermeta_cache_load_metadata( WP_DuckDB_Result_Statement $result, string $table_name = 'wptests_usermeta' ): void {
		$this->assertSame( 3, $result->columnCount() );

		foreach ( array( 'user_id', 'meta_key', 'meta_value' ) as $index => $column_name ) {
			$column_meta = $result->getColumnMeta( $index );
			$this->assertSame( $column_name, $column_meta['name'] );
			$this->assertSame( $table_name, $column_meta['table'] );
			$this->assertSame( $column_name, $column_meta['mysqli:orgname'] );
			$this->assertSame( $table_name, $column_meta['mysqli:orgtable'] );
			$this->assertSame( 'wp', $column_meta['mysqli:db'] );
		}
	}

	private function assert_wordpress_meta_cache_load_metadata( WP_DuckDB_Result_Statement $result, string $table_name, string $object_column ): void {
		$this->assertSame( 3, $result->columnCount() );

		foreach ( array( $object_column, 'meta_key', 'meta_value' ) as $index => $column_name ) {
			$column_meta = $result->getColumnMeta( $index );
			$this->assertSame( $column_name, $column_meta['name'] );
			$this->assertSame( $table_name, $column_meta['table'] );
			$this->assertSame( $column_name, $column_meta['mysqli:orgname'] );
			$this->assertSame( $table_name, $column_meta['mysqli:orgtable'] );
			$this->assertSame( 'wp', $column_meta['mysqli:db'] );
		}
	}

	private function assert_wordpress_options_single_option_metadata( WP_DuckDB_Result_Statement $result, string $column_name ): void {
		$column_meta = $result->getColumnMeta( 0 );
		$this->assertSame( $column_name, $column_meta['name'] );
		$this->assertSame( 'wptests_options', $column_meta['table'] );
		$this->assertSame( $column_name, $column_meta['mysqli:orgname'] );
		$this->assertSame( 'wptests_options', $column_meta['mysqli:orgtable'] );
		$this->assertSame( 'wp', $column_meta['mysqli:db'] );
	}

	private function assert_wordpress_posts_id_lookup_select_used_one_native_query( array $queries, int $id ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_posts' ) );
		$this->assertStringContainsString( 'SELECT * FROM "wptests_posts" WHERE "ID" = ' . $id . ' LIMIT 1', $queries[0] );
	}

	private function assert_wordpress_posts_slug_status_lookup_select_used_one_native_query( array $queries ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_posts' ) );
		$this->assertStringContainsString( 'SELECT "wptests_posts".* FROM "wptests_posts"', $queries[0] );
		$this->assertStringContainsString( '"wptests_posts"."post_name" = \'target-slug\'', $queries[0] );
		$this->assertStringContainsString( '"wptests_posts"."ID" NOT IN (2)', $queries[0] );
		$this->assertStringContainsString( '"wptests_posts"."post_type" IN (\'post\', \'page\', \'attachment\')', $queries[0] );
		$this->assertStringContainsString( '"wptests_posts"."post_status" = \'publish\'', $queries[0] );
		$this->assertStringContainsString( 'ORDER BY "wptests_posts"."post_date" DESC, "wptests_posts"."ID" DESC', $queries[0] );
	}

	private function assert_wordpress_term_relationships_distinct_terms_select_used_one_native_query( array $queries ): void {
		$this->assertCount( 1, $queries, implode( "\n", $queries ) );
		$this->assertSame( 0, $this->count_duckdb_table_resolution_queries( $queries ) );
		$this->assertSame( 0, $this->count_duckdb_column_metadata_queries( $queries, 'wptests_terms' ) );
		$this->assertStringContainsString( 'SELECT DISTINCT "t"."term_id" FROM "wptests_terms" AS "t"', $queries[0] );
		$this->assertStringContainsString( 'INNER JOIN "wptests_term_taxonomy" AS "tt"', $queries[0] );
		$this->assertStringContainsString( 'INNER JOIN "wptests_term_relationships" AS "tr"', $queries[0] );
		$this->assertStringContainsString( '"tt"."taxonomy" IN (\'wptests_tax\')', $queries[0] );
		$this->assertStringContainsString( '"tr"."object_id" IN (201, 202)', $queries[0] );
		$this->assertStringContainsString( 'ORDER BY "t"."name" ASC', $queries[0] );
	}

	private function assert_wordpress_posts_id_lookup_metadata( WP_DuckDB_Result_Statement $result, string $table_name ): void {
		$this->assertSame( 5, $result->columnCount() );

		$id_meta = $result->getColumnMeta( 0 );
		$this->assertSame( 'ID', $id_meta['name'] );
		$this->assertSame( $table_name, $id_meta['table'] );
		$this->assertSame( 'ID', $id_meta['mysqli:orgname'] );
		$this->assertSame( $table_name, $id_meta['mysqli:orgtable'] );
		$this->assertSame( 'wp', $id_meta['mysqli:db'] );
		$this->assertSame( 20, $id_meta['len'] );
		$this->assertSame( 8, $id_meta['mysqli:type'] );

		$title_meta = $result->getColumnMeta( 3 );
		$this->assertSame( 'post_title', $title_meta['name'] );
		$this->assertSame( $table_name, $title_meta['table'] );
		$this->assertSame( 'post_title', $title_meta['mysqli:orgname'] );
		$this->assertSame( $table_name, $title_meta['mysqli:orgtable'] );
		$this->assertSame( 252, $title_meta['mysqli:type'] );
	}

	private function assert_wordpress_posts_slug_status_lookup_metadata( WP_DuckDB_Result_Statement $result, string $table_name, array $columns ): void {
		$this->assertSame( count( $columns ), $result->columnCount() );

		foreach ( $columns as $index => $column_name ) {
			$column_meta = $result->getColumnMeta( $index );
			$this->assertSame( $column_name, $column_meta['name'] );
			$this->assertSame( $table_name, $column_meta['table'] );
			$this->assertSame( $column_name, $column_meta['mysqli:orgname'] );
			$this->assertSame( $table_name, $column_meta['mysqli:orgtable'] );
			$this->assertSame( 'wp', $column_meta['mysqli:db'] );
		}
	}

	private function assert_wordpress_posts_omitted_auto_increment_insert_used_returning( array $queries, string $column_name ): void {
		$this->assertNotEmpty( $queries );
		$last_query = end( $queries );
		$this->assertStringContainsString( 'INSERT INTO ', $last_query );
		$this->assertStringContainsString( 'wptests_posts', $last_query );
		$this->assertStringContainsString( ' RETURNING "' . $column_name . '"', $last_query );

		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'SELECT MAX("' . $column_name . '")', $query );
			$this->assertStringNotContainsString( 'SELECT currval(', $query );
		}
	}

	private function assert_wordpress_usermeta_omitted_auto_increment_insert_used_returning( array $queries, string $table_name ): void {
		$this->assertNotEmpty( $queries );
		$last_query = end( $queries );
		$this->assertStringContainsString( 'INSERT INTO ', $last_query );
		$this->assertStringContainsString( $table_name, $last_query );
		$this->assertStringContainsString( ' RETURNING "umeta_id"', $last_query );

		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'SELECT MAX("umeta_id")', $query );
			$this->assertStringNotContainsString( 'SELECT currval(', $query );
		}
	}

	private function count_duckdb_auto_increment_metadata_queries( array $queries, string $table_name ): int {
		$count = 0;
		foreach ( $queries as $query ) {
			if (
				false !== strpos( $query, 'SELECT column_name FROM "__wp_duckdb_column_metadata"' )
				&& false !== strpos( $query, "table_name = '" . $table_name . "'" )
				&& false !== strpos( $query, "extra = 'auto_increment'" )
			) {
				++$count;
			}
		}

		return $count;
	}

	private function count_duckdb_column_metadata_queries( array $queries, string $table_name ): int {
		$count = 0;
		foreach ( $queries as $query ) {
			if (
				false !== strpos( $query, 'FROM "__wp_duckdb_column_metadata"' )
				&& false !== strpos( $query, 'ORDER BY ordinal_position' )
				&& false !== strpos( $query, "'" . $table_name . "'" )
			) {
				++$count;
			}
		}

		return $count;
	}

	private function count_duckdb_table_resolution_queries( array $queries ): int {
		$count = 0;
		foreach ( $queries as $query ) {
			if ( false !== strpos( $query, 'information_schema.tables' ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function count_duckdb_information_schema_columns_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_columns"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_columns"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_columns"' );
				}
			)
		);
	}

	private function count_duckdb_information_schema_statistics_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_statistics"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_statistics"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_statistics"' );
				}
			)
		);
	}

	private function count_duckdb_information_schema_table_constraints_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_table_constraints"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_table_constraints"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_table_constraints"' );
				}
			)
		);
	}

	private function count_duckdb_information_schema_key_column_usage_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_key_column_usage"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_key_column_usage"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_key_column_usage"' );
				}
			)
		);
	}

	private function count_duckdb_information_schema_referential_constraints_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_referential_constraints"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_referential_constraints"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_referential_constraints"' );
				}
			)
		);
	}

	private function count_duckdb_information_schema_check_constraints_stage_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'CREATE OR REPLACE TEMP TABLE "__wp_duckdb_information_schema_check_constraints"' )
						|| false !== strpos( $query, 'INSERT INTO "__wp_duckdb_information_schema_check_constraints"' )
						|| false !== strpos( $query, 'FROM "__wp_duckdb_information_schema_check_constraints"' );
				}
			)
		);
	}

	private function duckdb_table_row_count_queries( array $queries ): array {
		return array_values(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'COUNT(*) AS table_rows' );
				}
			)
		);
	}

	private function count_duckdb_table_row_count_queries( array $queries ): int {
		return count( $this->duckdb_table_row_count_queries( $queries ) );
	}

	private function count_duckdb_currval_queries( array $queries ): int {
		return count(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, 'SELECT currval(' );
				}
			)
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

	private function create_wordpress_joined_group_by_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_title TEXT NOT NULL,
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_author, post_date, post_title, post_type) VALUES
				(1, 10, '2026-01-01 00:00:00', 'first', 'page'),
				(2, 20, '2026-02-01 00:00:00', 'second', 'page'),
				(3, 30, '2026-03-01 00:00:00', 'third', 'post')"
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
				(1, 1, '_wp_page_template', 'default'),
				(2, 1, '_wp_page_template', 'default'),
				(3, 2, '_wp_page_template', 'default'),
				(4, 3, '_wp_page_template', 'default'),
				(5, 2, '_edit_lock', 'ignored')"
		);
	}

	private function create_wordpress_left_join_group_by_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_term_relationships (
				object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				term_order INT(11) NOT NULL DEFAULT '0',
				PRIMARY KEY (object_id, term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
				(1, '2026-01-01 00:00:00', 'post', 'publish'),
				(2, '2026-02-01 00:00:00', 'post', 'publish'),
				(3, '2026-03-01 00:00:00', 'post', 'publish'),
				(4, '2026-04-01 00:00:00', 'page', 'publish')"
		);
		$driver->query(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
					(1, 11, 0),
					(1, 12, 0),
					(2, 11, 0),
					(4, 11, 0)'
		);
	}

	private function create_wordpress_taxonomy_group_by_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_terms (
				term_id BIGINT(20) UNSIGNED NOT NULL,
				name VARCHAR(200) NOT NULL DEFAULT '',
				slug VARCHAR(200) NOT NULL DEFAULT '',
				term_group BIGINT(10) NOT NULL DEFAULT 0,
				PRIMARY KEY (term_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_term_taxonomy (
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
				term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				taxonomy VARCHAR(32) NOT NULL DEFAULT '',
				description LONGTEXT NOT NULL,
				parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				count BIGINT(20) NOT NULL DEFAULT 0,
				PRIMARY KEY (term_taxonomy_id),
				UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			'CREATE TABLE wptests_term_relationships (
				object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				term_order INT(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_terms (term_id, name, slug, term_group) VALUES
				(1, 'Alpha', 'alpha', 0),
				(2, 'Beta', 'beta', 0),
				(3, 'Shared', 'shared', 0)"
		);
		$driver->query(
			"INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
				(101, 1, 'wptests_tax', 'First description', 0, 1),
				(102, 2, 'wptests_tax', 'Second description', 0, 1),
				(301, 3, 'category', 'Shared category', 0, 0),
				(302, 3, 'post_tag', 'Shared tag', 0, 0)"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_type, post_status) VALUES
				(201, 'post', 'publish'),
				(202, 'post', 'publish'),
				(203, 'page', 'publish')"
		);
		$driver->query(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
				(201, 101, 0),
				(202, 102, 0),
				(203, 101, 0)'
		);
	}

	private function create_wordpress_taxonomy_group_by_temporary_shadow_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TEMPORARY TABLE wptests_terms (
				term_id BIGINT(20) UNSIGNED NOT NULL,
				name VARCHAR(200) NOT NULL DEFAULT '',
				PRIMARY KEY (term_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TEMPORARY TABLE wptests_term_taxonomy (
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
				term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				taxonomy VARCHAR(32) NOT NULL DEFAULT '',
				PRIMARY KEY (term_taxonomy_id),
				UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			'CREATE TEMPORARY TABLE wptests_term_relationships (
				object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$driver->query(
			"INSERT INTO wptests_terms (term_id, name) VALUES
				(10, 'Apricot'),
				(20, 'Blueberry'),
				(30, 'Cherry')"
		);
		$driver->query(
			"INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES
				(1001, 20, 'wptests_tax'),
				(1002, 10, 'wptests_tax'),
				(1003, 30, 'post_tag')"
		);
		$driver->query(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id) VALUES
				(201, 1001),
				(201, 1002),
				(202, 1003)'
		);
	}

	private function create_wordpress_meta_query_found_rows_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_posts (
				ID BIGINT(20) UNSIGNED NOT NULL,
				post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_postmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
				(1, '2026-01-01 00:00:00', 'post', 'publish'),
				(2, '2026-02-01 00:00:00', 'post', 'publish'),
				(3, '2026-03-01 00:00:00', 'page', 'publish')"
		);
		$driver->query(
			"INSERT INTO wptests_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
				(1, 1, 'foo', 'buzz'),
				(2, 2, 'foo', 'bar'),
				(3, 1, 'decimal_value', '10.30'),
				(4, 2, 'decimal_value', '10.40'),
				(5, 3, 'foo', 'fizz')"
		);
	}

	private function create_wordpress_comment_group_by_tables( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_comments (
				comment_ID BIGINT(20) UNSIGNED NOT NULL,
				comment_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				comment_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
				PRIMARY KEY (comment_ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"CREATE TABLE wptests_commentmeta (
				meta_id BIGINT(20) UNSIGNED NOT NULL,
				comment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
				meta_key VARCHAR(255) DEFAULT NULL,
				meta_value LONGTEXT,
				PRIMARY KEY (meta_id),
				KEY comment_id (comment_id),
				KEY meta_key (meta_key(191))
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wptests_comments (comment_ID, comment_date, comment_date_gmt, comment_approved) VALUES
				(1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '1'),
				(2, '2026-02-01 00:00:00', '2026-02-01 00:00:00', '1'),
				(3, '2026-03-01 00:00:00', '2026-03-01 00:00:00', '1')"
		);
		$driver->query(
			"INSERT INTO wptests_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES
				(1, 1, 'key', 'alpha'),
				(2, 2, 'key', 'charlie'),
				(3, 3, 'key', 'bravo'),
				(4, 1, 'foo', 'alpha'),
				(5, 2, 'foo', 'bravo'),
				(6, 3, 'foo', 'charlie'),
				(7, 1, 'bar', 'zulu'),
				(8, 2, 'bar', 'yankee'),
				(9, 3, 'bar', 'xray')"
		);
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
