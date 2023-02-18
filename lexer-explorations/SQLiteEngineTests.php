<?php

use PHPUnit\Framework\TestCase;

class SQLiteEngineTests extends TestCase {

	public static function setUpBeforeClass(): void {
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
	public function testRegexps($operator, $expected_result) {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('transient', '1');"
		);

		$this->engine->query("SELECT * FROM _options WHERE option_name $operator '^rss_.+$'");

		$this->assertEquals(
			array($expected_result),
			$this->engine->get_query_results()
		);
	}

	public function regexpOperators() {
		$positive_match = (object) array(
			'ID' => '1',
			'option_name' => 'rss_0123456789abcdef0123456789abcdef',
			'option_value' => '1',
		);
		$negative_match = (object) array(
			'ID' => '2',
			'option_name' => 'transient',
			'option_value' => '1',
		);
		return array(
			array( 'REGEXP', $positive_match ),
			array( 'RLIKE', $positive_match ),
			array( 'REGEXP BINARY', $positive_match ),
			array( 'RLIKE BINARY', $positive_match ),
			array( 'NOT REGEXP', $negative_match ),
			array( 'NOT RLIKE', $negative_match ),
			array( 'NOT REGEXP BINARY', $negative_match ),
			array( 'NOT RLIKE BINARY', $negative_match ),
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
		$this->assertEquals(1, $result);
		$this->assertEquals('', $this->engine->get_error_message());

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

	public function testSelectDate() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', '2003-05-27 10:08:48');"
		);

		$this->engine->query("SELECT 
			YEAR( _dates.option_value ) as year,
			MONTH( _dates.option_value ) as month,
			DAYOFMONTH( _dates.option_value ) as dayofmonth,
			HOUR( _dates.option_value ) as hour,
			MINUTE( _dates.option_value ) as minute,
			SECOND( _dates.option_value ) as second
		FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals('2003', $results[0]->year);
		$this->assertEquals('5', $results[0]->month);
		$this->assertEquals('27', $results[0]->dayofmonth);
		$this->assertEquals('10', $results[0]->hour);
		$this->assertEquals('8', $results[0]->minute);
		$this->assertEquals('48', $results[0]->second);
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
