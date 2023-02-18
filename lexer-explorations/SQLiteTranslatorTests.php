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

	public function testCalcFoundRows() {
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
			"SELECT SQL_CALC_FOUND_ROWS * FROM wptests_dummy"
		);
		$this->assertEquals(
			'SELECT  * FROM wptests_dummy',
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
				'[ALTER TABLE] Rewrites data types (INT -> integer)',
				'ALTER TABLE `table` ADD COLUMN `column` INT;',
				array(
					WP_SQLite_Translator::get_query_object( 'ALTER TABLE `table` ADD COLUMN `column` integer;' ),
				),
			),
			array(
				'[ALTER TABLE] Rewrites data types – case-insensitive (int -> integer)',
				'ALTER TABLE `table` ADD COLUMN `column` int;',
				array(
					WP_SQLite_Translator::get_query_object( 'ALTER TABLE `table` ADD COLUMN `column` integer;' ),
				),
			),
			array(
				'[ALTER TABLE] Ignores fulltext keys',
				'ALTER TABLE wptests_dbdelta_test ADD FULLTEXT KEY `key_5` (`column_1`)',
				array(
					WP_SQLite_Translator::get_query_object( 'SELECT 1 WHERE 1=0;' ),
				),
			),
			array(
				'[ALTER TABLE] Transforms ADD KEY into a CREATE INDEX query',
				'ALTER TABLE wptests_dbdelta_test ADD KEY `key_5` (`column_1`)',
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                    CREATE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`)
                    SQL
					),
				),
			),
			array(
				'[ALTER TABLE] Transforms ADD UNIQUE KEY into a CREATE UNIQUE INDEX query',
				'ALTER TABLE wptests_dbdelta_test ADD UNIQUE KEY `key_5` (`column_1`)',
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                    CREATE UNIQUE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`)
                    SQL
					),
				),
			),
			array(
				'[ALTER TABLE] Removes fields sizes from ADD KEY queries',
				'ALTER TABLE wptests_dbdelta_test ADD UNIQUE KEY `key_5` (`column_1`(250),`column_2`(250))',
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                    CREATE UNIQUE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`,`column_2`)
                    SQL
					),
				),
			),
			array(
				'Translates SELECT queries (1)',
				'SELECT * FROM wp_options',
				array(
					WP_SQLite_Translator::get_query_object( 'SELECT * FROM wp_options' ),
				),
			),
			array(
				'Translates SELECT with DATE_ADD',
				'SELECT DATE_ADD(post_date_gmt, INTERVAL "0" SECOND) FROM wptests_posts',
				array(
					WP_SQLite_Translator::get_query_object( "SELECT DATE(post_date_gmt,   '+0 SECOND') FROM wptests_posts" ),
				),
			),
			array(
				'Translates REGEXP BINARY',
				"SELECT * FROM wptests_dummy WHERE option_name REGEXP BINARY '^rss_.+$'",
				array(
					WP_SQLite_Translator::get_query_object(
						"SELECT * FROM wptests_dummy WHERE option_name REGEXP  :param0",
						array(
							':param0' => '^rss_.+$'
						)
					)
				),
			),
			array(
				'Translates SELECT RLIKE',
				"SELECT * FROM wptests_dummy WHERE option_name RLIKE '^rss_.+$'",
				array(
					WP_SQLite_Translator::get_query_object( 
						"SELECT * FROM wptests_dummy WHERE option_name REGEXP :param0",
						array(
							':param0' => '^rss_.+$'
						)
					),
				),
			),
			array(
				'Translates SELECT queries (3)',
				"SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM wptests_posts  WHERE post_type = 'post' AND post_status = 'publish' GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC",
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                            SELECT STRFTIME('%Y',post_date) AS `year`, STRFTIME('%M',post_date) AS `month`, count(ID) as posts FROM wptests_posts  WHERE post_type = :param0  AND post_status = :param1  GROUP BY STRFTIME('%Y',post_date), STRFTIME('%M',post_date) ORDER BY post_date DESC
                        SQL,
						array(
							':param0' => 'post',
							':param1' => 'publish',
						)
					),
				),
			),
			array(
				'Translates UPDATE queries',
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
				'Translates DELETE queries',
				'DELETE FROM wptests_usermeta WHERE user_id != 1',
				array(
					WP_SQLite_Translator::get_query_object(
						<<<'SQL'
                            DELETE FROM wptests_usermeta WHERE user_id != 1
                        SQL,
						array()
					),
				),
			),
			array(
				'Translates START TRANSACTION queries',
				'START TRANSACTION',
				array(
					WP_SQLite_Translator::get_query_object( 'BEGIN' ),
				),
			),
			array(
				'Translates BEGIN queries',
				'BEGIN',
				array( WP_SQLite_Translator::get_query_object( 'BEGIN' ) ),
			),
			array(
				'Translates ROLLBACK queries',
				'ROLLBACK',
				array( WP_SQLite_Translator::get_query_object( 'ROLLBACK' ) ),
			),
			array(
				'Translates COMMIT queries',
				'COMMIT',
				array( WP_SQLite_Translator::get_query_object( 'COMMIT' ) ),
			),
			array(
				'Translates DESCRIBE queries',
				'DESCRIBE wp_test',
				array( WP_SQLite_Translator::get_query_object( 'PRAGMA table_info("wp_test");' ) ),
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
			array(
				'Stringifies COUNT(*) queries',
				'SELECT post_author, COUNT(*) FROM wptests_posts',
				array( WP_SQLite_Translator::get_query_object( "SELECT post_author, COUNT(*) FROM wptests_posts" ) ),
			),
			array(
				'Translates a complex INSERT',
				'INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES (\'_transient_woocommerce_admin_payment_method_promotion_specs\', \'a:1:{s:5:\"en_US\";a:1:{s:20:\"woocommerce_payments\";O:8:\"stdClass\":8:{s:2:\"id\";s:20:\"woocommerce_payments\";s:5:\"title\";s:20:\"WooCommerce Payments\";s:7:\"content\";s:369:\"Payments made simple, with no monthly fees – designed exclusively for WooCommerce stores. Accept credit cards, debit cards, and other popular payment methods.<br/><br/>By clicking “Install”, you agree to the <a href=\"https://wordpress.com/tos/\" target=\"_blank\">Terms of Service</a> and <a href=\"https://automattic.com/privacy/\" target=\"_blank\">Privacy policy</a>.\";s:5:\"image\";s:101:\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/wcpay.svg\";s:7:\"plugins\";a:1:{i:0;s:20:\"woocommerce-payments\";}s:10:\"is_visible\";a:2:{i:0;O:8:\"stdClass\":6:{s:4:\"type\";s:6:\"option\";s:12:\"transformers\";a:2:{i:0;O:8:\"stdClass\":2:{s:3:\"use\";s:12:\"dot_notation\";s:9:\"arguments\";O:8:\"stdClass\":1:{s:4:\"path\";s:8:\"industry\";}}i:1;O:8:\"stdClass\":2:{s:3:\"use\";s:12:\"array_column\";s:9:\"arguments\";O:8:\"stdClass\":1:{s:3:\"key\";s:4:\"slug\";}}}s:11:\"option_name\";s:30:\"woocommerce_onboarding_profile\";s:9:\"operation\";s:9:\"!contains\";s:5:\"value\";s:31:\"cbd-other-hemp-derived-products\";s:7:\"default\";a:0:{}}i:1;O:8:\"stdClass\":2:{s:4:\"type\";s:2:\"or\";s:8:\"operands\";a:19:{i:0;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"US\";s:9:\"operation\";s:1:\"=\";}i:1;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"PR\";s:9:\"operation\";s:1:\"=\";}i:2;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"AU\";s:9:\"operation\";s:1:\"=\";}i:3;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"CA\";s:9:\"operation\";s:1:\"=\";}i:4;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"DE\";s:9:\"operation\";s:1:\"=\";}i:5;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"ES\";s:9:\"operation\";s:1:\"=\";}i:6;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"FR\";s:9:\"operation\";s:1:\"=\";}i:7;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"GB\";s:9:\"operation\";s:1:\"=\";}i:8;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"IE\";s:9:\"operation\";s:1:\"=\";}i:9;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"IT\";s:9:\"operation\";s:1:\"=\";}i:10;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"NZ\";s:9:\"operation\";s:1:\"=\";}i:11;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"AT\";s:9:\"operation\";s:1:\"=\";}i:12;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"BE\";s:9:\"operation\";s:1:\"=\";}i:13;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"NL\";s:9:\"operation\";s:1:\"=\";}i:14;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"PL\";s:9:\"operation\";s:1:\"=\";}i:15;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"PT\";s:9:\"operation\";s:1:\"=\";}i:16;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"CH\";s:9:\"operation\";s:1:\"=\";}i:17;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"HK\";s:9:\"operation\";s:1:\"=\";}i:18;O:8:\"stdClass\":3:{s:4:\"type\";s:21:\"base_location_country\";s:5:\"value\";s:2:\"SG\";s:9:\"operation\";s:1:\"=\";}}}}s:9:\"sub_title\";s:865:\"<img class=\"wcpay-visa-icon wcpay-icon\" src=\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/visa.svg\" alt=\"Visa\"><img class=\"wcpay-mastercard-icon wcpay-icon\" src=\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/mastercard.svg\" alt=\"Mastercard\"><img class=\"wcpay-amex-icon wcpay-icon\" src=\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/amex.svg\" alt=\"Amex\"><img class=\"wcpay-googlepay-icon wcpay-icon\" src=\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/googlepay.svg\" alt=\"Googlepay\"><img class=\"wcpay-applepay-icon wcpay-icon\" src=\"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/applepay.svg\" alt=\"Applepay\">\";s:15:\"additional_info\";O:8:\"stdClass\":1:{s:18:\"experiment_version\";s:2:\"v2\";}}}}\', \'no\') ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)',
				array( 
					WP_SQLite_Translator::get_query_object( 
						'INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES (:param0 , :param1 , :param2 ) ON CONFLICT   (option_name) DO UPDATE SET  `option_name` = excluded.`option_name`, `option_value` = excluded.`option_value`, `autoload` = excluded.`autoload`',
						array(
							':param0' => '_transient_woocommerce_admin_payment_method_promotion_specs',
							':param1' => 'a:1:{s:5:"en_US";a:1:{s:20:"woocommerce_payments";O:8:"stdClass":8:{s:2:"id";s:20:"woocommerce_payments";s:5:"title";s:20:"WooCommerce Payments";s:7:"content";s:369:"Payments made simple, with no monthly fees – designed exclusively for WooCommerce stores. Accept credit cards, debit cards, and other popular payment methods.<br/><br/>By clicking “Install”, you agree to the <a href="https://wordpress.com/tos/" target="_blank">Terms of Service</a> and <a href="https://automattic.com/privacy/" target="_blank">Privacy policy</a>.";s:5:"image";s:101:"https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/wcpay.svg";s:7:"plugins";a:1:{i:0;s:20:"woocommerce-payments";}s:10:"is_visible";a:2:{i:0;O:8:"stdClass":6:{s:4:"type";s:6:"option";s:12:"transformers";a:2:{i:0;O:8:"stdClass":2:{s:3:"use";s:12:"dot_notation";s:9:"arguments";O:8:"stdClass":1:{s:4:"path";s:8:"industry";}}i:1;O:8:"stdClass":2:{s:3:"use";s:12:"array_column";s:9:"arguments";O:8:"stdClass":1:{s:3:"key";s:4:"slug";}}}s:11:"option_name";s:30:"woocommerce_onboarding_profile";s:9:"operation";s:9:"!contains";s:5:"value";s:31:"cbd-other-hemp-derived-products";s:7:"default";a:0:{}}i:1;O:8:"stdClass":2:{s:4:"type";s:2:"or";s:8:"operands";a:19:{i:0;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"US";s:9:"operation";s:1:"=";}i:1;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"PR";s:9:"operation";s:1:"=";}i:2;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"AU";s:9:"operation";s:1:"=";}i:3;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"CA";s:9:"operation";s:1:"=";}i:4;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"DE";s:9:"operation";s:1:"=";}i:5;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"ES";s:9:"operation";s:1:"=";}i:6;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"FR";s:9:"operation";s:1:"=";}i:7;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"GB";s:9:"operation";s:1:"=";}i:8;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"IE";s:9:"operation";s:1:"=";}i:9;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"IT";s:9:"operation";s:1:"=";}i:10;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"NZ";s:9:"operation";s:1:"=";}i:11;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"AT";s:9:"operation";s:1:"=";}i:12;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"BE";s:9:"operation";s:1:"=";}i:13;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"NL";s:9:"operation";s:1:"=";}i:14;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"PL";s:9:"operation";s:1:"=";}i:15;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"PT";s:9:"operation";s:1:"=";}i:16;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"CH";s:9:"operation";s:1:"=";}i:17;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"HK";s:9:"operation";s:1:"=";}i:18;O:8:"stdClass":3:{s:4:"type";s:21:"base_location_country";s:5:"value";s:2:"SG";s:9:"operation";s:1:"=";}}}}s:9:"sub_title";s:865:"<img class="wcpay-visa-icon wcpay-icon" src="https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/visa.svg" alt="Visa"><img class="wcpay-mastercard-icon wcpay-icon" src="https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/mastercard.svg" alt="Mastercard"><img class="wcpay-amex-icon wcpay-icon" src="https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/amex.svg" alt="Amex"><img class="wcpay-googlepay-icon wcpay-icon" src="https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/googlepay.svg" alt="Googlepay"><img class="wcpay-applepay-icon wcpay-icon" src="https://woocommerce.com/wp-content/plugins/wccom-plugins/payment-gateway-suggestions/images/icons/applepay.svg" alt="Applepay">";s:15:"additional_info";O:8:"stdClass":1:{s:18:"experiment_version";s:2:"v2";}}}}',
							':param2' => 'no',
						)
					)
				),
			),
			array(
				'Translates the CREATE TABLE wptests_users query',
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
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci",
				array(
					WP_SQLite_Translator::get_query_object(
						<<<SQL
                      CREATE TABLE "wptests_users" (
                      "ID" integer PRIMARY KEY AUTOINCREMENT NOT NULL,
                      "user_login" text NOT NULL DEFAULT '',
                      "user_pass" text NOT NULL DEFAULT '',
                      "user_nicename" text NOT NULL DEFAULT '',
                      "user_email" text NOT NULL DEFAULT '',
                      "user_url" text NOT NULL DEFAULT '',
                      "user_registered" text NOT NULL DEFAULT '0000-00-00 00:00:00',
                      "user_activation_key" text NOT NULL DEFAULT '',
                      "user_status" integer NOT NULL DEFAULT '0',
                      "display_name" text NOT NULL DEFAULT '')
                      SQL
					),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wptests_users__user_login_key" ON "wptests_users" ("user_login")' ),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wptests_users__user_nicename" ON "wptests_users" ("user_nicename")' ),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wptests_users__user_email" ON "wptests_users" ("user_email")' ),
				),
			),
			array(
				'Translates the CREATE TABLE wp_terms query',
				"CREATE TABLE wp_terms (
					term_id bigint(20) unsigned NOT NULL auto_increment,
					name varchar(200) NOT NULL default '',
					slug varchar(200) NOT NULL default '',
					term_group bigint(10) NOT NULL default 0,
					PRIMARY KEY  (term_id),
					KEY slug (slug(250)),
					KEY name (name(250))
				   ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci",
				array(
					WP_SQLite_Translator::get_query_object(
						<<<SQL
                      CREATE TABLE "wp_terms" (
                      "term_id" integer PRIMARY KEY AUTOINCREMENT NOT NULL,
                      "name" text NOT NULL DEFAULT '',
                      "slug" text NOT NULL DEFAULT '',
                      "term_group" integer NOT NULL DEFAULT 0)
                      SQL
					),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wp_terms__slug" ON "wp_terms" ("slug")' ),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wp_terms__name" ON "wp_terms" ("name")' ),
				),
			),
			array(
				'Translates the CREATE TABLE wp_term_taxonomy query',
				"CREATE TABLE IF NOT EXISTS wp_term_taxonomy (
					term_taxonomy_id bigint(20) unsigned NOT NULL auto_increment,
					term_id bigint(20) unsigned NOT NULL default 0,
					taxonomy varchar(32) NOT NULL default '',
					description longtext NOT NULL,
					parent bigint(20) unsigned NOT NULL default 0,
					count bigint(20) NOT NULL default 0,
					PRIMARY KEY  (term_taxonomy_id),
					UNIQUE KEY term_id_taxonomy (term_id,taxonomy),
					KEY taxonomy (taxonomy)
				   ) ;",
				array(
					WP_SQLite_Translator::get_query_object(
						<<<SQL
                      CREATE TABLE IF NOT EXISTS "wp_term_taxonomy" (
                      "term_taxonomy_id" integer PRIMARY KEY AUTOINCREMENT NOT NULL,
                      "term_id" integer NOT NULL DEFAULT 0,
                      "taxonomy" text NOT NULL DEFAULT '',
                      "description" text NOT NULL,
                      "parent" integer NOT NULL DEFAULT 0,
                      "count" integer NOT NULL DEFAULT 0)
                      SQL
					),
					WP_SQLite_Translator::get_query_object( 'CREATE UNIQUE  INDEX "wp_term_taxonomy__term_id_taxonomy" ON "wp_term_taxonomy" ("term_id", "taxonomy")' ),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wp_term_taxonomy__taxonomy" ON "wp_term_taxonomy" ("taxonomy")' ),
				),
			),
			array(
				'Translates the CREATE TABLE wp_options query',
				"CREATE TABLE wp_options (
                      option_id bigint(20) unsigned NOT NULL auto_increment,
                      option_name varchar(191) NOT NULL default '',
                      option_value longtext NOT NULL,
                      autoload varchar(20) NOT NULL default 'yes',
                      PRIMARY KEY  (option_id),
                      UNIQUE KEY option_name (option_name),
                      KEY autoload (autoload)
                      ) ;",
				array(
					WP_SQLite_Translator::get_query_object(
						<<<SQL
                      CREATE TABLE "wp_options" (
                      "option_id" integer PRIMARY KEY AUTOINCREMENT NOT NULL,
                      "option_name" text NOT NULL DEFAULT '',
                      "option_value" text NOT NULL,
                      "autoload" text NOT NULL DEFAULT 'yes')
                      SQL
					),
					WP_SQLite_Translator::get_query_object( 'CREATE UNIQUE  INDEX "wp_options__option_name" ON "wp_options" ("option_name")' ),
					WP_SQLite_Translator::get_query_object( 'CREATE  INDEX "wp_options__autoload" ON "wp_options" ("autoload")' ),
				),
			),
		);
	}

}
