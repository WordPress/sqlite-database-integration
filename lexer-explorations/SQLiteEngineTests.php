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

	public function testRlike() {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('transient', '1');"
		);

		$this->engine->query("SELECT * FROM _options WHERE option_name RLIKE '^rss_.+$'");

		$this->assertEquals(
			array(
				(object) array(
					'ID' => '1',
					'option_name' => 'rss_0123456789abcdef0123456789abcdef',
					'option_value' => '1',
				),
			),
			$this->engine->get_query_results()
		);
	}

	public function testRegexpBinary() {
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('rss_0123456789abcdef0123456789abcdef', '1');"
		);
		$this->engine->query(
			"INSERT INTO _options (option_name, option_value) VALUES ('transient', '1');"
		);

		$this->engine->query("SELECT * FROM _options WHERE option_name REGEXP BINARY '^rss_.+$'");

		$this->assertEquals(
			array(
				(object) array(
					'ID' => '1',
					'option_name' => 'rss_0123456789abcdef0123456789abcdef',
					'option_value' => '1',
				),
			),
			$this->engine->get_query_results()
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

	public function testUpdateDate() {
		$this->engine->query(
			"INSERT INTO _dates (option_name, option_value) VALUES ('first', now());"
		);

		$this->engine->query("SELECT YEAR(option_value) as y FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals(date('Y'), $results[0]->y);


		$this->engine->query(
			"UPDATE _dates SET option_value = DATE_SUB(option_value, INTERVAL '2' YEAR);"
		);

		$this->engine->query("SELECT YEAR(option_value) as y FROM _dates");

		$results = $this->engine->get_query_results();
		$this->assertCount(1, $results);
		$this->assertEquals(date('Y') - 2, $results[0]->y);
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
