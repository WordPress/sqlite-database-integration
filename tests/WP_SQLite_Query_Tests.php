<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests using the WordPress table definitions.
 */
class WP_SQLite_Query_Tests extends TestCase {

	private $engine;
	private $sqlite;

	public static function setUpBeforeClass(): void {
		// if ( ! defined( 'PDO_DEBUG' )) {
		// define( 'PDO_DEBUG', true );
		// }
		if ( ! defined( 'FQDB' ) ) {
			define( 'FQDB', ':memory:' );
			define( 'FQDBDIR', __DIR__ . '/../testdb' );
		}
		error_reporting( E_ALL & ~E_DEPRECATED );
		if ( ! isset( $GLOBALS['table_prefix'] ) ) {
			$GLOBALS['table_prefix'] = 'wptests_';
		}
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']                  = new stdClass();
			$GLOBALS['wpdb']->suppress_errors = false;
			$GLOBALS['wpdb']->show_errors     = true;
		}
	}

	/**
	 *  Before each test, we create a new volatile database and WordPress tables.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function setUp(): void {
		/* This is the DDL for WordPress tables in SQLite syntax. */
		global $blog_tables;
		$queries = explode( ';', $blog_tables );

		$this->sqlite = new PDO( 'sqlite::memory:' );
		$this->engine = new WP_SQLite_Translator( $this->sqlite );

		$translator = $this->engine;

		try {
			$translator->begin_transaction();
			foreach ( $queries as $query ) {
				$query = trim( $query );
				if ( empty( $query ) ) {
					continue;
				}

				$result = $translator->execute_sqlite_query( $query );
				if ( false === $result ) {
					throw new PDOException( $translator->get_error_message() );
				}
			}
			$translator->commit();
		} catch ( PDOException $err ) {
			$err_data =
				$err->errorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$err_code = $err_data[1];
			$translator->rollback();
			$message  = sprintf(
				'Error occurred while creating tables or indexes...<br />Query was: %s<br />',
				var_export( $query, true )
			);
			$message .= sprintf( 'Error message is: %s', $err_data[2] );
			wp_die( $message, 'Database Error!' );
		}

		/* Mock up some metadata rows. When meta_key starts with _, the custom field isn't visible to the editor.  */
		for ( $i = 1; $i <= 40; $i ++ ) {
			$k1 = 'visible_meta_key_' . str_pad( $i, 2, '0', STR_PAD_LEFT );
			$k2 = '_invisible_meta_key_' . str_pad( $i, 2, '0', STR_PAD_LEFT );
			$this->assertQuery(
				"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, '$k1', '$k1-value');"
			);
			$this->assertQuery(
				"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, '$k2', '$k2-value');"
			);
		}

		/* Mock up some transients for testing. Site transients. Two expired, one in the future. */
		$time = - 15;
		foreach ( array( 'tag1', 'tag2', 'tag3' ) as $tag ) {
			$tv = '_site_transient_' . $tag;
			$tt = '_site_transient_timeout_' . $tag;
			$this->assertQuery(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$tv', '$tag', 'no');"
			);
			$this->assertQuery(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$tt', UNIX_TIMESTAMP() + $time, 'no');"
			);
			$time += 10;
		}
		/* Ordinary transients. */
		$time = - 15;
		foreach ( array( 'tag4', 'tag5', 'tag6' ) as $tag ) {
			$tv = '_transient_' . $tag;
			$tt = '_transient_timeout_' . $tag;
			$this->assertQuery(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$tv', '$tag', 'no');"
			);
			$this->assertQuery(
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$tt', UNIX_TIMESTAMP() + $time, 'no');"
			);
			$time += 10;
		}
	}

	private function assertQuery( $sql ) {
		$retval = $this->engine->query( $sql );
		$this->assertEquals(
			'',
			$this->engine->get_error_message()
		);
		$this->assertNotFalse(
			$retval
		);

		return $retval;
	}

	public function testGreatestLeast () {
		$q = <<<'QUERY'
SELECT GREATEST('a', 'b') letter;
QUERY;

		$result = $this->assertQuery( $q );
		$actual = $this->engine->get_query_results();
		$this->assertEquals(1, count( $actual ));
		$this->assertEquals('b', $actual[0]->letter);

		$q = <<<'QUERY'
SELECT LEAST('a', 'b') letter;
QUERY;

		$result = $this->assertQuery( $q );
		$actual = $this->engine->get_query_results();
		$this->assertEquals(1, count( $actual ));
		$this->assertEquals('a', $actual[0]->letter);
	}

	public function testLikeEscapingSimpleNoSemicolon() {
		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key LIKE '\_%'
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 40, count( $actual ) );
	}

	public function testLikeEscapingSimpleSemicolon() {
		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key LIKE '\_%';
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 40, count( $actual ) );
	}

	public function testLikeEscapingBasic() {
		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' AND meta_key NOT LIKE '\_%' ORDER BY meta_key LIMIT 30
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 30, count( $actual ) );
		$last = $actual[ count( $actual ) - 1 ]->meta_key;
		$this->assertEquals( 'visible_meta_key_30', $last );
	}

	public function testLikeEscapingParenAfterLike() {
		$q = <<<'QUERY'
	SELECT DISTINCT meta_key
      FROM wp_postmeta
	 WHERE (meta_key != 'hello' AND meta_key NOT LIKE '\_%') AND meta_id > 0
QUERY;

		$this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 40, count( $actual ) );
		$last = $actual[ count( $actual ) - 1 ]->meta_key;
		$this->assertEquals( 'visible_meta_key_40', $last );
	}

	// https://github.com/WordPress/sqlite-database-integration/issues/19

	public function testLikeEscapingWithConcatFunction() {
		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' AND meta_key NOT LIKE CONCAT('\_', '%') ORDER BY meta_key LIMIT 30
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 30, count( $actual ) );
		$last = $actual[ count( $actual ) - 1 ]->meta_key;
		$this->assertEquals( 'visible_meta_key_30', $last );
	}

	public function testHavingWithoutGroupBy() {

		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' HAVING meta_key NOT LIKE '\_%' ORDER BY meta_key LIMIT 30
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 30, count( $actual ) );
		$last = $actual[ count( $actual ) - 1 ]->meta_key;
		$this->assertEquals( 'visible_meta_key_30', $last );

		$q = <<<'QUERY'
SELECT DISTINCT meta_key FROM wp_postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' HAVING meta_key NOT LIKE CONCAT('\_', '%') ORDER BY meta_key LIMIT 30
QUERY;

		$result = $this->assertQuery( $q );

		$actual = $this->engine->get_query_results();
		$this->assertEquals( 30, count( $actual ) );
		$last = $actual[ count( $actual ) - 1 ]->meta_key;
		$this->assertEquals( 'visible_meta_key_30', $last );
	}

	public function testCharLengthSimple() {
		$query = <<<'QUERY'
SELECT * FROM wp_options WHERE LENGTH(option_name) != CHAR_LENGTH(option_name)
QUERY;

		$this->assertQuery( $query );
		$actual = $this->engine->get_query_results();
		$this->assertEquals( 0, count( $actual ) );
	}

	public function testSubstringSimple() {
		$query = <<<'QUERY'
SELECT SUBSTR(option_name, 1) ss1, SUBSTRING(option_name, 1) sstr1,
       SUBSTR(option_name, -2) es1, SUBSTRING(option_name, -2) estr1
FROM wp_options
WHERE SUBSTR(option_name, -2) !=  SUBSTRING(option_name, -2)
  OR  SUBSTR(option_name, 1) !=  SUBSTRING(option_name, 1)
QUERY;

		$this->assertQuery( $query );
		$actual = $this->engine->get_query_results();
		$this->assertEquals( 0, count( $actual ) );
	}

	public function testCharLengthComplex() {
		$query = <<<'QUERY'
SELECT option_name,
       CHAR_LENGTH(
				CASE WHEN option_name LIKE '\_site\_transient\_%'
				     THEN '_site_transient_'
                     WHEN option_name LIKE '\_transient\_%'
				     THEN '_transient_'
				     ELSE '' END
			) prefix_length,

       SUBSTR(option_name, CHAR_LENGTH(
				CASE WHEN option_name LIKE '\_site\_transient\_%'
				     THEN '_site_transient_'
                     WHEN option_name LIKE '\_transient\_%'
				     THEN '_transient_'
				     ELSE '' END
			) + 1) suffix
FROM wp_options
WHERE option_name LIKE '\_%transient\_%'
AND option_name NOT LIKE '%\_transient\_timeout\_%'
QUERY;

		$this->assertQuery( $query );
		$actual = $this->engine->get_query_results();
		$this->assertEquals( 6, count( $actual ) );
		foreach ( $actual as $row ) {
			self::assertTrue( str_ends_with( $row->option_name, '_' . $row->suffix ) );
		}
	}

	public function testAllTransients() {
		$this->assertQuery(
			"SELECT * FROM wp_options WHERE option_name LIKE '\_%transient\_%'"
		);
		$actual = $this->engine->get_query_results();
		$this->assertEquals( 12, count( $actual ) );
	}

	public function testExpiredTransients() {
		$query = <<<'QUERY'
SELECT a.option_id, a.option_name, a.option_value as option_content, a.autoload, b.option_value as option_timeout,
      CONCAT (
			CASE WHEN a.option_name LIKE '\_site\_transient\_%'
				THEN '_site_transient_timeout_'
				ELSE '_transient_timeout_'
			END,
			SUBSTRING(a.option_name, CHAR_LENGTH(
				CASE WHEN a.option_name LIKE '\_site\_transient\_%'
				   THEN '_site_transient_'
				   ELSE '_transient_'
				END
			) + 1)) AS timeout_name


  FROM wp_options a LEFT JOIN wp_options b ON b.option_name =
		CONCAT(
			CASE WHEN a.option_name LIKE '\_site\_transient\_%'
				THEN '_site_transient_timeout_'
				ELSE '_transient_timeout_'
			END
			,
			SUBSTRING(a.option_name, CHAR_LENGTH(
				CASE WHEN a.option_name LIKE '\_site\_transient\_%'
				   THEN '_site_transient_'
				   ELSE '_transient_'
				END
			) + 1)
		)
		WHERE (a.option_name LIKE '\_transient\_%' OR a.option_name LIKE '\_site\_transient\_%')
		AND a.option_name NOT LIKE '%\_transient\_timeout\_%'
		AND b.option_value < UNIX_TIMESTAMP()
QUERY;

		$this->assertQuery( $query );
		$actual = $this->engine->get_query_results();
		$this->assertEquals( 4, count( $actual ) );
		foreach ( $actual as $row ) {
			self::assertLessThan( time(), $row->option_timeout );
		}
	}

}
