<?php

use PHPUnit\Framework\TestCase;

class SQLiteEngineTests extends TestCase {

	public static function setUpBeforeClass(): void {
		// if ( ! defined( 'PDO_DEBUG' )) {
		// 	define( 'PDO_DEBUG', true );
		// }
		if(!defined('FQDB')) {
			define( 'FQDB', ':memory:' );
			define( 'FQDBDIR', __DIR__ . '/../testdb' );
		}
		if(!class_exists('WP_SQLite_PDO_Engine')) {
			error_reporting(E_ALL & ~E_DEPRECATED);
			require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-pdo-engine.php';
		}
		if(!isset($GLOBALS['table_prefix'])){
			$GLOBALS['table_prefix'] = 'wptests_';
		}
		if(!isset($GLOBALS['wpdb'])){
			$GLOBALS['wpdb'] = new stdClass();
			$GLOBALS['wpdb']->suppress_errors = false;
			$GLOBALS['wpdb']->show_errors = true;
		}
	}

	private $engine;

	// Before each test, we create a new database
	public function setUp(): void {
		$this->engine = new WP_SQLite_PDO_Engine( );
		$this->engine->query(
			"CREATE TABLE _options (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				option_name TEXT NOT NULL default '',
				option_value TEXT NOT NULL default ''
			);"
		);
		$this->engine->query(
			"CREATE TABLE _dates (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				option_name TEXT NOT NULL default '',
				option_value DATE NOT NULL
			);"
		);
	}

	public function testRegexp() {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('transient', '1');"
		);

		$this->engine->query("DELETE FROM _options WHERE option_name  REGEXP '^rss_.+$'");
		$this->engine->query('SELECT * FROM _options');
		$this->assertCount( 1, $this->engine->get_query_results() );
	}

	/**
	 * @dataProvider regexpOperators
	 */
	public function testRegexps($operator, $regexp, $expected_result) {
		$this->engine->query(
			"INSERT INTO _options (option_name) VALUES ('rss_123'), ('RSS_123'), ('transient');"
		);

		$success = $this->engine->query("SELECT ID, option_name FROM _options WHERE option_name $operator '$regexp' ORDER BY id LIMIT 1");
		$this->assertNotFalse($success);
		
		$this->assertEquals('', $this->engine->get_error_message());

		$this->assertEquals(
			array($expected_result),
			$this->engine->get_query_results()
		);
	}

	public function regexpOperators() {
		$lowercase_rss = (object) array(
			'ID' => '1',
			'option_name' => 'rss_123',
		);
		$uppercase_RSS = (object) array(
			'ID' => '2',
			'option_name' => 'RSS_123',
		);
		$lowercase_transient = (object) array(
			'ID' => '3',
			'option_name' => 'transient',
		);
		return array(
			array( 'REGEXP', '^RSS_.+$', $lowercase_rss ),
			array( 'RLIKE','^RSS_.+$', $lowercase_rss ),
			array( 'REGEXP BINARY', '^RSS_.+$', $uppercase_RSS ),
			array( 'RLIKE BINARY', '^RSS_.+$', $uppercase_RSS ),
			array( 'NOT REGEXP', '^RSS_.+$', $lowercase_transient ),
			array( 'NOT RLIKE', '^RSS_.+$', $lowercase_transient ),
			array( 'NOT REGEXP BINARY', '^RSS_.+$', $lowercase_rss ),
			array( 'NOT RLIKE BINARY', '^RSS_.+$', $lowercase_rss ),
		);
	}

	public function testInsertDateNow() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', now());"
		);

		$this->engine->query("SELECT YEAR(option_value) as y FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals(date('Y'), $results[0]->y);
	}
	
	public function testCastAsBinary() {
		$this->engine->query(
			// Use a confusing alias to make sure it replaces only the correct token
			"SELECT CAST('ABC' AS BINARY) as binary;" 
		);
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('ABC', $results[0]->binary);
	}
	
	public function testCreateTemporaryTable() {
		$this->engine->query(
			"CREATE TEMPORARY TABLE _tmp_table (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				option_name TEXT NOT NULL default '',
				option_value TEXT NOT NULL default ''
			);"
		);
		$this->assertEquals('', $this->engine->get_error_message());

		$this->engine->query(
			"DROP TEMPORARY TABLE _tmp_table;"
		);
		$this->assertEquals('', $this->engine->get_error_message());
	}
	
	public function testCreateTable() {
		$result = $this->engine->query(
			"CREATE TABLE wptests_users (
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
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
		);
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertEquals(1, $result);

		$this->engine->query("DESCRIBE wptests_users;");
		$results = $this->engine->get_query_results();
		$this->assertEquals(
			array (
				0 => 
				(object) array(
					'cid' => '0',
					'name' => 'ID',
					'type' => 'INTEGER',
					'notnull' => '1',
					'dflt_value' => NULL,
					'pk' => '1',
				),
				1 => 
				(object) array(
					'cid' => '1',
					'name' => 'user_login',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				2 => 
				(object) array(
					'cid' => '2',
					'name' => 'user_pass',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				3 => 
				(object) array(
					'cid' => '3',
					'name' => 'user_nicename',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				4 => 
				(object) array(
					'cid' => '4',
					'name' => 'user_email',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				5 => 
				(object) array(
					'cid' => '5',
					'name' => 'user_url',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				6 => 
				(object) array(
					'cid' => '6',
					'name' => 'user_registered',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "'0000-00-00 00:00:00'",
					'pk' => '0',
				),
				7 => 
				(object) array(
					'cid' => '7',
					'name' => 'user_activation_key',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
				8 => 
				(object) array(
					'cid' => '8',
					'name' => 'user_status',
					'type' => 'INTEGER',
					'notnull' => '1',
					'dflt_value' => "'0'",
					'pk' => '0',
				),
				9 => 
				(object) array(
					'cid' => '9',
					'name' => 'display_name',
					'type' => 'TEXT',
					'notnull' => '1',
					'dflt_value' => "''",
					'pk' => '0',
				),
			),
			$results
		);
	}
	
	public function testCaseInsensitiveUniqueIndex() {
		$this->engine->query(
			"CREATE TABLE _tmp_table (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				name varchar(20) NOT NULL default '',
				UNIQUE KEY name (name)
			);"
		);
		$result1 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('first');");
		$this->assertEquals(1, $result1);

		$result2 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('FIRST');");
		$this->assertFalse($result2);
	}

	public function testOnDuplicateUpdate() {
		$this->engine->query(
			"CREATE TABLE _tmp_table (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				name varchar(20) NOT NULL default '',
				UNIQUE KEY myname (name)
			);"
		);
		$this->assertEquals('', $this->engine->get_error_message());

		$result1 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('first');");
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertEquals(1, $result1);

		$result2 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('FIRST') ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);");
		$this->assertEquals(1, $result2);

		$this->engine->query("SELECT * FROM _tmp_table;");
		$this->assertCount(1, $this->engine->get_query_results());
		$this->assertEquals(
			array(
				(object) array(
					'name' => 'FIRST',
					'ID' => 1
				),
			),
			$this->engine->get_query_results()
		);
	}

	public function testTruncatesInvalidDates() {
		$this->engine->query("INSERT INTO _dates (option_value) VALUES ('2022-01-01 14:24:12');");
		$this->engine->query("INSERT INTO _dates (option_value) VALUES ('2022-31-01 14:24:12');");

		$this->engine->query("SELECT * FROM _dates;");
		$results = $this->engine->get_query_results();
		$this->assertCount(2, $results);
		$this->assertEquals('2022-01-01 14:24:12', $results[0]->option_value);
		$this->assertEquals('0000-00-00 00:00:00', $results[1]->option_value);
	}

	public function testCaseInsensitiveSelect() {
		$this->engine->query(
			"CREATE TABLE _tmp_table (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				name varchar(20) NOT NULL default ''
			);"
		);
		$this->engine->query(
			"INSERT INTO _tmp_table (name) VALUES ('first');"
		);
		$this->engine->query("SELECT name FROM _tmp_table WHERE name = 'FIRST';");
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertCount(1, $this->engine->get_query_results());
		$this->assertEquals(
			array(
				(object) array(
					'name' => 'first',
				),
			),
			$this->engine->get_query_results()
		);
	}

	public function testSelectBetweenDates() {
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('first', '2016-01-15T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('second', '2016-01-16T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('third', '2016-01-17T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('fourth', '2016-01-18T00:00:00Z');");
		
		$this->engine->query("SELECT * FROM _dates WHERE option_value BETWEEN '2016-01-15T00:00:00Z' AND '2016-01-17T00:00:00Z' ORDER BY ID;");
		$results = $this->engine->get_query_results();
		$this->assertCount(3, $results);
		$this->assertEquals('first', $results[0]->option_name);
		$this->assertEquals('second', $results[1]->option_name);
		$this->assertEquals('third', $results[2]->option_name);
	}

	public function testSelectFilterByDatesGtLt() {
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('first', '2016-01-15T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('second', '2016-01-16T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('third', '2016-01-17T00:00:00Z');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('fourth', '2016-01-18T00:00:00Z');");
		
		$this->engine->query("
			SELECT * FROM _dates
			WHERE option_value > '2016-01-15 00:00:00'
			AND   option_value < '2016-01-17 00:00:00'
			ORDER BY ID
		");
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('second', $results[0]->option_name);
	}

	public function testSelectFilterByDatesZeroHour() {
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('first', '2014-10-21 00:42:29');");
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('second', '2014-10-21 01:42:29');");
		
		$this->engine->query("
			SELECT * FROM _dates
			WHERE YEAR(option_value) = 2014
			AND   MONTHNUM(option_value) = 10
			AND   DAY(option_value) = 21
			AND   HOUR(option_value) = 0
			AND   MINUTE(option_value) = 42
		");
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('first', $results[0]->option_name);
	}

	public function testCorrectlyInsertsDatesAndStrings() {
		$this->engine->query("INSERT INTO _dates (option_name, option_value) VALUES ('2016-01-15T00:00:00Z', '2016-01-15T00:00:00Z');");
		
		$this->engine->query("SELECT * FROM _dates");
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2016-01-15 00:00:00', $results[0]->option_value);
		if($results[0]->option_name !== '2016-01-15T00:00:00Z') {
			$this->markTestSkipped('A datetime-like string was rewritten to an SQLite format even though it was used as a text and not as a datetime.');
		}
		$this->assertEquals('2016-01-15T00:00:00Z', $results[0]->option_name);
	}

	public function testTransactionRollback() {
		$this->engine->query('BEGIN');
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('first');");
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(1, $this->engine->get_query_results());
		$this->engine->query('ROLLBACK');

		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(0, $this->engine->get_query_results());
	}

	public function testTransactionCommit() {
		$this->engine->query('BEGIN');
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('first');");
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(1, $this->engine->get_query_results());
		$this->engine->query('COMMIT');

		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(1, $this->engine->get_query_results());
	}

	public function testStartTransactionCommand() {
		$this->engine->query('START TRANSACTION');
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('first');");
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(1, $this->engine->get_query_results());
		$this->engine->query('ROLLBACK');

		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(0, $this->engine->get_query_results());
	}

	public function testNestedTransactionHasNoEffect() {
		$this->engine->query('BEGIN');
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('first');");
		$this->engine->query('START TRANSACTION');
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('second');");
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(2, $this->engine->get_query_results());

		$this->engine->query('ROLLBACK');
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(0, $this->engine->get_query_results());

		$this->engine->query('COMMIT');
		$this->engine->query("SELECT * FROM _options;");
		$this->assertCount(0, $this->engine->get_query_results());
	}

	public function testCount() {
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('first');");
		$this->engine->query("INSERT INTO _options (option_name) VALUES ('second');");
		$this->engine->query("SELECT COUNT(*) as count FROM _options;");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertSame('2', $results[0]->count);
	}

	public function testUpdateDate() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2003-05-27 10:08:48');"
		);

		$this->engine->query("SELECT option_value FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2003-05-27 10:08:48', $results[0]->option_value);


		$this->engine->query(
			"UPDATE _dates SET option_value = DATE_SUB(option_value, INTERVAL '2' YEAR);"
		);

		$this->engine->query("SELECT option_value FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2001-05-27 10:08:48', $results[0]->option_value);
	}

	public function testInsertDateLiteral() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2003-05-27 10:08:48');"
		);

		$this->engine->query("SELECT option_value FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2003-05-27 10:08:48', $results[0]->option_value);
	}

	public function testSelectDate1() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2000-05-27 10:08:48');"
		);

		$this->engine->query("SELECT 
			YEAR( _dates.option_value ) as year,
			MONTH( _dates.option_value ) as month,
			DAYOFMONTH( _dates.option_value ) as dayofmonth,
			MONTHNUM( _dates.option_value ) as monthnum,
			WEEKDAY( _dates.option_value ) as weekday,
			WEEK( _dates.option_value, 1 ) as week1,
			HOUR( _dates.option_value ) as hour,
			MINUTE( _dates.option_value ) as minute,
			SECOND( _dates.option_value ) as second
		FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2000', $results[0]->year);
		$this->assertEquals('5', $results[0]->month);
		$this->assertEquals('27', $results[0]->dayofmonth);
		$this->assertEquals('5', $results[0]->weekday);
		$this->assertEquals('21', $results[0]->week1);
		$this->assertEquals('5', $results[0]->monthnum);
		$this->assertEquals('10', $results[0]->hour);
		$this->assertEquals('8', $results[0]->minute);
		$this->assertEquals('48', $results[0]->second);
	}

	public function testSelectDate24HourFormat() {
		$this->engine->query( "
			INSERT INTO _dates (option_name, option_value) 
			VALUES 
				('second', '2003-05-27 14:08:48'),
				('first', '2003-05-27 00:08:48');
		" );

		// HOUR(14:08) should yield 14 in the 24 hour format
		$this->engine->query( "SELECT  HOUR( _dates.option_value ) as hour FROM _dates WHERE option_name = 'second'" );
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('14', $results[0]->hour);

		// HOUR(00:08) should yield 0 in the 24 hour format
		$this->engine->query( "SELECT  HOUR( _dates.option_value ) as hour FROM _dates WHERE option_name = 'first'" );
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('0', $results[0]->hour);

		// Lookup by HOUR(00:08) = 0 should yield the right record
		$this->engine->query( 
			"SELECT  HOUR( _dates.option_value ) as hour FROM _dates 
			WHERE HOUR(_dates.option_value) = 0 " );

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('0', $results[0]->hour);
	}

	public function testSelectByDateFunctions() {
		$this->engine->query( "
			INSERT INTO _dates (option_name, option_value) 
			VALUES ('second', '2014-10-21 00:42:29');
		" );

		// HOUR(14:08) should yield 14 in the 24 hour format
		$this->engine->query( "
			SELECT * FROM _dates WHERE
              year(option_value) = 2014
              AND monthnum(option_value) = 10
              AND day(option_value) = 21
              AND hour(option_value) = 0
              AND minute(option_value) = 42
		" );
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
	}

	public function testSelectByDateFormat() {
		$this->engine->query( "
			INSERT INTO _dates (option_name, option_value) 
			VALUES ('second', '2014-10-21 00:42:29');
		" );

		// HOUR(14:08) should yield 14 in the 24 hour format
		$this->engine->query( "
			SELECT * FROM _dates WHERE DATE_FORMAT(option_value, '%H.%i') = 0.42
		" );
		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
	}
	
	public function testInsertOnDuplicateKey() {
		$this->engine->query(
			"CREATE TABLE _tmp_table (
				ID INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				name varchar(20) NOT NULL default '',
				UNIQUE KEY name (name)
			);"
		);
		$result1 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('first');");
		$this->assertEquals(1, $result1);

		$result2 = $this->engine->query("INSERT INTO _tmp_table (name) VALUES ('FIRST') ON DUPLICATE KEY SET name=VALUES(`name`);");
		$this->assertEquals(1, $result2);


		$this->engine->query( "SELECT COUNT(*) as cnt FROM _tmp_table" );
		$results = $this->engine->get_query_results();
		$this->assertEquals(1, $results[0]->cnt);
	}
	
	public function testCreateTableCompositePk() {
		$this->engine->query(
			"CREATE TABLE wptests_term_relationships (
				object_id bigint(20) unsigned NOT NULL default 0,
				term_taxonomy_id bigint(20) unsigned NOT NULL default 0,
				term_order int(11) NOT NULL default 0,
				PRIMARY KEY  (object_id,term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			   ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
		);
		$result1 = $this->engine->query("INSERT INTO wptests_term_relationships VALUES (1,2,1),(1,3,2);");
		$this->assertEquals(2, $result1);

		$result2 = $this->engine->query("INSERT INTO wptests_term_relationships VALUES (1,2,2),(1,3,1);");
		$this->assertEquals(false, $result2);
	}

	public function testInsertOnDuplicateKeyCompositePk() {
		$result = $this->engine->query(
			"CREATE TABLE wptests_term_relationships (
				object_id bigint(20) unsigned NOT NULL default 0,
				term_taxonomy_id bigint(20) unsigned NOT NULL default 0,
				term_order int(11) NOT NULL default 0,
				PRIMARY KEY  (object_id,term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			   ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
		);
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertTrue($result);

		$result1 = $this->engine->query("INSERT INTO wptests_term_relationships VALUES (1,2,1),(1,3,2);");
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertEquals(2, $result1);

		$result2 = $this->engine->query("INSERT INTO wptests_term_relationships VALUES (1,2,2),(1,3,1) ON DUPLICATE KEY SET term_order = VALUES(term_order);");
		$this->assertEquals('', $this->engine->get_error_message());
		$this->assertEquals(2, $result2);

		$this->engine->query( "SELECT COUNT(*) as cnt FROM wptests_term_relationships" );
		$results = $this->engine->get_query_results();
		$this->assertEquals(2, $results[0]->cnt);
	}
	
	public function testStringToFloatComparison() {
		$this->engine->query( "SELECT ('00.42' = 0.4200) as cmp;" );
		$results = $this->engine->get_query_results();
		if($results[0]->cmp !== 1) {
			$this->markTestSkipped('Comparing a string and a float returns true in MySQL. In SQLite, they\'re different. Skipping. ');
		}
		$this->assertEquals('1', $results[0]->cmp);
	}

	public function testComplexSelectBasedOnDates() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2003-05-27 10:08:48');"
		);

		$this->engine->query("SELECT SQL_CALC_FOUND_ROWS  _dates.ID
		FROM _dates
		WHERE YEAR( _dates.option_value ) = 2003 AND MONTH( _dates.option_value ) = 5 AND DAYOFMONTH( _dates.option_value ) = 27
		ORDER BY _dates.option_value DESC
		LIMIT 0, 10");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
	}

	public function testUpdateReturnValue() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2003-05-27 10:08:48');"
		);

		$return = $this->engine->query(
			"UPDATE _dates SET option_value = '2001-05-27 10:08:48'"
		);
		$this->assertSame(1, $return, 'UPDATE query did not return 1 when one row was changed');

		$return = $this->engine->query(
			"UPDATE _dates SET option_value = '2001-05-27 10:08:48'"
		);
		if($return === 1) {
			$this->markTestIncomplete(
				'SQLite UPDATE query returned 1 when no rows were changed. ' .
				'This is a database compatibility issue – MySQL would return 0 '.
				'in the same scenario.'
			);
		}
		$this->assertSame(0, $return, 'UPDATE query did not return 0 when no rows were changed');
	}

	public function testOrderByField() {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('User 0000019', 'second');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('User 0000020', 'third');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('User 0000018', 'first');"
		);

		$this->engine->query('SELECT FIELD(option_name, "User 0000018", "User 0000019", "User 0000020") as sorting_order FROM _options ORDER BY FIELD(option_name, "User 0000018", "User 0000019", "User 0000020")');

		$this->assertEquals(
			array(
				(object) array(
					'sorting_order' => '1',
				),
				(object) array(
					'sorting_order' => '2',
				),
				(object) array(
					'sorting_order' => '3',
				),
			),
			$this->engine->get_query_results()
		);

		$this->engine->query('SELECT option_value FROM _options ORDER BY FIELD(option_name, "User 0000018", "User 0000019", "User 0000020")');

		$this->assertEquals(
			array(
				(object) array(
					'option_value' => 'first',
				),
				(object) array(
					'option_value' => 'second',
				),
				(object) array(
					'option_value' => 'third',
				),
			),
			$this->engine->get_query_results()
		);
	}

	public function testFetchedDataIsStringified() {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);

		$this->engine->query('SELECT ID FROM _options');

		$this->assertEquals(
			array(
				(object) array(
					'ID' => '1',
				),
			),
			$this->engine->get_query_results()
		);
	}

}
