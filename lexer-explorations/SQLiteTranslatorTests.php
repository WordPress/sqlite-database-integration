<?php

require __DIR__ . '/class-wp-sqlite-translator.php';

use PHPUnit\Framework\TestCase;

class SQLiteTranslatorTests extends TestCase {


	public function testSimpleQuery() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$this->assertEquals(
			array(
				array(
					1,
					'b' => 1,
				),
			),
			$this->runQuery( $sqlite, 'SELECT 1 as "b"' )[1]
		);
	}

	public function testCreateTableQuery() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$this->runQuery(
			$sqlite,
			<<<'Q'
            CREATE TABLE IF NOT EXISTS wptests_users (
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
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
            Q
		);
		$this->runQuery(
			$sqlite,
			<<<'Q'
            INSERT INTO wptests_users VALUES (1,'admin','$P$B5ZQZ5ZQZ5ZQZ5ZQZ5ZQZ5ZQZ5ZQZ5','admin','admin@localhost', '', '2019-01-01 00:00:00', '', 0, 'admin');
            Q
		);
		$rows = $this->runQuery( $sqlite, 'SELECT * FROM wptests_users' )[1];
		$this->assertCount( 1, $rows );

		$result = $this->runQuery( $sqlite, 'SELECT SQL_CALC_FOUND_ROWS * FROM wptests_users' )[0];
		$this->assertEquals(
			array(
				array(
					0              => 1,
					'FOUND_ROWS()' => 1,
				),
			),
			$this->runQuery( $sqlite, 'SELECT FOUND_ROWS()', $result->calc_found_rows )[1]
		);
	}

	public function runQuery( $sqlite, string $query, $last_found_rows = null ) {
		$t      = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate( $query, $last_found_rows );
		foreach ( $result->queries as $query ) {
			$last_stmt = $sqlite->prepare( $query->sql );
			$last_stmt->execute( $query->params );
		}
		if ( true === $result->has_result ) {
			return array( $result, $result->result );
		}
		return array(
			$result,
			$last_stmt->fetchAll(),
		);
	}

	/**
	 * @dataProvider getSqliteQueryTypeTestCases
	 */
	public function testRecognizeSqliteQueryType( $query, $expected_sqlite_query_type ) {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t      = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$this->assertEquals(
			$expected_sqlite_query_type,
			$t->translate( $query )->sqlite_query_type
		);
	}

	public function getSqliteQueryTypeTestCases() {
		return array(
			array(
				'ALTER TABLE `table` ADD COLUMN `column` INT;',
				'ALTER',
			),
			array(
				'DESCRIBE `table`;',
				'PRAGMA',
			),
		);
	}

	public function testTranslatesComplexDelete() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$sqlite->query(
			"CREATE TABLE wptests_dummy (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				user_login TEXT NOT NULL default '',
				option_name TEXT NOT NULL default '',
				option_value TEXT NOT NULL default ''
			);"
		);
		$sqlite->query(
			"INSERT INTO wptests_dummy (user_login, option_name, option_value) VALUES ('admin', '_transient_timeout_test', '1675963960');"
		);
		$sqlite->query(
			"INSERT INTO wptests_dummy (user_login, option_name, option_value) VALUES ('admin', '_transient_test', '1675963960');"
		);

		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate(
			"DELETE a, b FROM wptests_dummy a, wptests_dummy b
				WHERE a.option_name LIKE '_transient_%'
				AND a.option_name NOT LIKE '_transient_timeout_%'
				AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) );"
		);
		$this->assertEquals(
			'DELETE FROM wptests_dummy WHERE ID IN (2,1)',
			$result->queries[0]->sql
		);
	}

	public function testTranslatesInfoSchemaSelect() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$sqlite->query(
			"CREATE TABLE wptests_dummy (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				user_login TEXT NOT NULL default '',
				option_name TEXT NOT NULL default '',
				option_value TEXT NOT NULL default ''
			);"
		);

		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate(
			"SELECT TABLE_NAME AS 'table', TABLE_ROWS AS 'rows', SUM(data_length + index_length) as 'bytes' FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'abc' AND TABLE_NAME IN ('wptests_dummy') GROUP BY TABLE_NAME;"
		);
		$this->assertEquals(
			"SELECT name as `table`, (CASE  WHEN name = 'sqlite_sequence' THEN 0  WHEN name = 'wptests_dummy' THEN 0 ELSE 0 END)  as `rows`, 0 as `bytes` FROM sqlite_master WHERE type='table' ORDER BY name",
			$result->queries[0]->sql
		);
	}

	public function testTranslatesDoubleAlterTable() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate(
			"ALTER TABLE test DROP INDEX domain, ADD INDEX domain(domain(140),path(51)), DROP INDEX domain"
		);
		$this->assertCount(3, $result->queries);
		$this->assertEquals(
			'DROP INDEX "test__domain"',
			$result->queries[0]->sql
		);
		$this->assertEquals(
			'CREATE INDEX "test__domain" ON "test" (`domain`,`path`)',
			$result->queries[1]->sql
		);
		$this->assertEquals(
			'DROP INDEX "test__domain"',
			$result->queries[0]->sql
		);
	}

	public function testTranslatesComplexSelect() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$sqlite->query(
			$t->translate(
				"CREATE TABLE wptests_postmeta (
					meta_id bigint(20) unsigned NOT NULL auto_increment,
					post_id bigint(20) unsigned NOT NULL default '0',
					meta_key varchar(255) default NULL,
					meta_value longtext,
					PRIMARY KEY  (meta_id),
					KEY post_id (post_id),
					KEY meta_key (meta_key(191))
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
			)->queries[0]->sql
		);
		$sqlite->query(
			$t->translate(
				"CREATE TABLE wptests_posts (
					ID bigint(20) unsigned NOT NULL auto_increment,
					post_status varchar(20) NOT NULL default 'open',
					post_type varchar(20) NOT NULL default 'post',
					post_date varchar(20) NOT NULL default 'post',
					PRIMARY KEY  (ID)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
			)->queries[0]->sql
		);
		$result = $t->translate(
			"SELECT SQL_CALC_FOUND_ROWS  wptests_posts.ID
				FROM wptests_posts  INNER JOIN wptests_postmeta ON ( wptests_posts.ID = wptests_postmeta.post_id )
				WHERE 1=1 
				AND (
					NOT EXISTS (
						SELECT 1 FROM wptests_postmeta mt1 
						WHERE mt1.post_ID = wptests_postmeta.post_ID 
						LIMIT 1
					)
				)
				 AND (
					(wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish'))
				)
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);

		// No exception is good enough of a test for now
		$this->assertTrue(true);
	}	

	public function testTranslatesUtf8Insert() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate(
			"INSERT INTO test VALUES('ąłółźćę†','ąłółźćę†','ąłółźćę†')"
		);
		$this->assertEquals(
			"INSERT INTO test VALUES(:param0 ,:param1 ,:param2 )",
			$result->queries[0]->sql
		);
	}
	
	public function testTranslatesRandom() {
		$sqlite = new PDO( 'sqlite::memory:' );
		new WP_SQLite_PDO_User_Defined_Functions($sqlite);
		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$rand = $t->translate('SELECT RAND()')->queries[0]->sql;
		$this->assertIsNumeric(
			$sqlite->query($rand)->fetchColumn()
		);

		$rand = $t->translate('SELECT RAND(5)')->queries[0]->sql;
		$this->assertIsNumeric(
			$sqlite->query($rand)->fetchColumn()
		);
	}
	
	public function testTranslatesUtf8SELECT() {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$result = $t->translate(
			"SELECT a as 'ą' FROM test WHERE b='ąłółźćę†'AND c='ąłółźćę†'"
		);
		$this->assertEquals(
			"SELECT a as 'ą' FROM test WHERE b=:param0 AND c=:param1",
			$result->queries[0]->sql
		);
	}

	/**
	 * @dataProvider getTestCases
	 */
	public function testTranslate( $msg, $query, $expected_translation ) {
		$sqlite = new PDO( 'sqlite::memory:' );
		$t      = new WP_SQLite_Translator( $sqlite, 'wptests_' );
		$this->assertEquals(
			$expected_translation,
			$t->translate( $query )->queries,
			$msg
		);
	}

	public function getTestCases() {
		return array(
			array(
				'Translates SELECT with DATE_ADD',
				'SELECT DATE_ADD(post_date_gmt, INTERVAL "0" SECOND) FROM wptests_posts',
				array(
					WP_SQLite_Translator::get_query_object( "SELECT DATETIME(post_date_gmt,   '+0 SECOND') FROM wptests_posts" ),
				),
			),
			array(
				'Translates UPDATE queries with a "count" column – does not mistake it for a COUNT(*) function',
				'UPDATE wptests_term_taxonomy SET count = 0',
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                            UPDATE wptests_term_taxonomy SET count = 0
                        SQL,
						array()
					),
				),
			),
			array(
				'Ignores SET queries',
				'SET autocommit = 0;',
				array( WP_SQLite_Translator::get_query_object( 'SELECT 1 WHERE 1=0;' ) ),
			),
			array(
				'Ignores CALL queries',
				'CALL `test_mysqli_flush_sync_procedure`',
				array( WP_SQLite_Translator::get_query_object( 'SELECT 1 WHERE 1=0;' ) ),
			),
			array(
				'Ignores DROP PROCEDURE queries',
				'DROP PROCEDURE IF EXISTS `test_mysqli_flush_sync_procedure`',
				array( WP_SQLite_Translator::get_query_object( 'SELECT 1 WHERE 1=0;' ) ),
			),
			array(
				'Ignores CREATE PROCEDURE queries',
				'CREATE PROCEDURE `test_mysqli_flush_sync_procedure` BEGIN END',
				array( WP_SQLite_Translator::get_query_object( 'SELECT 1 WHERE 1=0;' ) ),
			),
		);
	}

}
