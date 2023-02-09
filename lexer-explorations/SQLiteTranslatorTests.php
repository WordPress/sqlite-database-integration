<?php

require __DIR__ . '/translator.php';

use PHPUnit\Framework\TestCase;

class SQLiteTranslatorTests extends TestCase
{

    public function testSimpleQuery()
    {
        $sqlite = new PDO( 'sqlite::memory:' );
        $this->assertEquals(
            [[1,'b'=>1]],
            $this->runQuery($sqlite, 'SELECT 1 as "b"')[1]
        );
    }
    
    public function testCreateTableQuery()
    {
        $sqlite = new PDO( 'sqlite::memory:' );
        $this->runQuery(
            $sqlite,
            <<<'Q'
            CREATE TABLE wptests_users (
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
        $rows = $this->runQuery($sqlite, 'SELECT * FROM wptests_users')[1];
        $this->assertCount(1, $rows);

        $result = $this->runQuery($sqlite, 'SELECT SQL_CALC_FOUND_ROWS * FROM wptests_users')[0];
        $this->assertEquals(
            [[
                0 => 1,
                "FOUND_ROWS()" => 1,
            ]],
            $this->runQuery($sqlite, 'SELECT FOUND_ROWS()', $result->calc_found_rows)[1]
        );
    }

	public function runQuery($sqlite, string $query, $last_found_rows=null) {
        $t = new SQLiteTranslator($sqlite, 'wptests_');
        $result = $t->translate($query, $last_found_rows);
        foreach($result->queries as $query){
            $last_stmt = $sqlite->prepare($query->sql);
            $last_stmt->execute($query->params);
        }
        if($result->has_result === true){
            return [$result, $result->result];
        }
        return [
            $result,
            $last_stmt->fetchAll()
        ];
    }

    /**
     * @dataProvider getTestCases
     */
    public function testTranslate($msg, $query, $expectedTranslation)
    {
        $sqlite = new PDO( 'sqlite::memory:' );
        $t = new SQLiteTranslator($sqlite, 'wptests_');
        $this->assertEquals(
            $expectedTranslation,
            $t->translate($query)->queries,
            $msg
        );
    }

    public function getTestCases() {
        return [
            [
                "[ALTER TABLE] Rewrites data types (INT -> integer)",
                "ALTER TABLE `table` ADD COLUMN `column` INT;",
                [
                    new SQLiteQuery("ALTER TABLE `table` ADD COLUMN `column` integer;")
                ]
            ],
            [
                "[ALTER TABLE] Rewrites data types – case-insensitive (int -> integer)",
                "ALTER TABLE `table` ADD COLUMN `column` int;",
                [
                    new SQLiteQuery("ALTER TABLE `table` ADD COLUMN `column` integer;")
                ]
            ],
            [
                "[ALTER TABLE] Ignores fulltext keys",
                "ALTER TABLE wptests_dbdelta_test ADD FULLTEXT KEY `key_5` (`column_1`)",
                [
                    new SQLiteQuery("SELECT 1=1")
                ]                
            ],
            [
                "[ALTER TABLE] Transforms ADD KEY into a CREATE INDEX query",
                "ALTER TABLE wptests_dbdelta_test ADD KEY `key_5` (`column_1`)",
                [
                    new SQLiteQuery(<<<'SQL'
                    CREATE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`)
                    SQL)
                ]                
            ],
            [
                "[ALTER TABLE] Transforms ADD UNIQUE KEY into a CREATE UNIQUE INDEX query",
                "ALTER TABLE wptests_dbdelta_test ADD UNIQUE KEY `key_5` (`column_1`)",
                [
                    new SQLiteQuery(<<<'SQL'
                    CREATE UNIQUE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`)
                    SQL)
                ]                
            ],
            [
                "[ALTER TABLE] Removes fields sizes from ADD KEY queries",
                "ALTER TABLE wptests_dbdelta_test ADD UNIQUE KEY `key_5` (`column_1`(250),`column_2`(250))",
                [
                    new SQLiteQuery(<<<'SQL'
                    CREATE UNIQUE INDEX "wptests_dbdelta_test__key_5" ON "wptests_dbdelta_test" ( `column_1`,`column_2`)
                    SQL)
                ]                
            ],
            [
                "Translates SELECT queries (1)",
                "SELECT * FROM wp_options",
                [
                    new SQLiteQuery('SELECT * FROM wp_options')
                ]
            ],
            [
                "Translates SELECT queries (2)",
                "SELECT SQL_CALC_FOUND_ROWS * FROM wptests_users",
                [
                    new SQLiteQuery('SELECT * FROM wptests_users')
                ]
            ],
            [
                "Translates SELECT queries (3)",
                "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM wptests_posts  WHERE post_type = 'post' AND post_status = 'publish' GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC",
                [
                    new SQLiteQuery(
                        <<<'SQL'
                            SELECT STRFTIME('%Y',post_date) AS `year`, STRFTIME('%M',post_date) AS `month`, count(ID) as posts FROM wptests_posts  WHERE post_type = :param0 AND post_status = :param1 GROUP BY STRFTIME('%Y',post_date), STRFTIME('%M',post_date) ORDER BY post_date DESC
                        SQL,
                        [
                            ':param0' => 'post',
                            ':param1' => 'publish',
                        ]
                    ),
                ]
            ],
            [
                "Translates UPDATE queries",
                "UPDATE wptests_term_taxonomy SET count = 0",
                [
                    new SQLiteQuery(
                        <<<'SQL'
                            UPDATE wptests_term_taxonomy SET count = 0
                        SQL,
                        []
                    ),
                ]
            ],
            [
                "Translates DELETE queries",
                "DELETE a, b FROM wp_options a, wp_options b
                    WHERE a.option_name LIKE '_transient_%'
                    AND a.option_name NOT LIKE '_transient_timeout_%'
                    AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
                    AND b.option_value < 1675963967;",
                [
                    new SQLiteQuery(
                        "SELECT a, b FROM wp_options a, wp_options b
                    WHERE a.option_name LIKE '_transient_%'
                    AND a.option_name NOT LIKE '_transient_timeout_%'
                    AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
                    AND b.option_value < 1675963967;",
                        []
                    ),
                ]
            ],
            [
                "Translates DELETE queries",
                "DELETE FROM wptests_usermeta WHERE user_id != 1",
                [
                    new SQLiteQuery(
                        <<<'SQL'
                            DELETE FROM wptests_usermeta WHERE user_id != 1
                        SQL,
                        []
                    ),
                ]
            ],
            [
                "Translates START TRANSACTION queries",
                "START TRANSACTION",
                [
                    new SQLiteQuery('START TRANSACTION'),
                ]
            ],
            [
                "Translates BEGIN queries",
                "BEGIN",
                [new SQLiteQuery('BEGIN'),]
            ],
            [
                "Translates ROLLBACK queries",
                "ROLLBACK",
                [new SQLiteQuery('ROLLBACK'),]
            ],
            [
                "Translates COMMIT queries",
                "COMMIT",
                [new SQLiteQuery('COMMIT'),]
            ],
            [
                "Ignores SET queries",
                "SET autocommit = 0;",
                [new SQLiteQuery('SELECT 1=1'),]
            ],
            [
                "Ignores CALL queries",
                "CALL `test_mysqli_flush_sync_procedure`",
                [new SQLiteQuery('SELECT 1=1'),]
            ],
            [
                "Ignores DROP PROCEDURE queries",
                "DROP PROCEDURE IF EXISTS `test_mysqli_flush_sync_procedure`",
                [new SQLiteQuery('SELECT 1=1'),]
            ],
            [
                "Ignores CREATE PROCEDURE queries",
                "CREATE PROCEDURE `test_mysqli_flush_sync_procedure` BEGIN END",
                [new SQLiteQuery('SELECT 1=1'),]
            ],
            [
                "Translates CREATE TABLE queries",
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
                [
                    new SQLiteQuery(<<<SQL
                        CREATE TABLE wptests_users (
                        "ID" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
                        "user_login" text NOT NULL DEFAULT '',
                        "user_pass" text NOT NULL DEFAULT '',
                        "user_nicename" text NOT NULL DEFAULT '',
                        "user_email" text NOT NULL DEFAULT '',
                        "user_url" text NOT NULL DEFAULT '',
                        "user_registered" text NOT NULL DEFAULT '0000-00-00 00:00:00',
                        "user_activation_key" text NOT NULL DEFAULT '',
                        "user_status" integer NOT NULL DEFAULT '0',
                        "display_name" text NOT NULL DEFAULT ''
                      )
                      SQL
                    ),
                    new SQLiteQuery('CREATE  INDEX "wptests_users__user_login_key" ON "wptests_users" ("user_login")'),
                    new SQLiteQuery('CREATE  INDEX "wptests_users__user_nicename" ON "wptests_users" ("user_nicename")'),
                    new SQLiteQuery('CREATE  INDEX "wptests_users__user_email" ON "wptests_users" ("user_email")'),
                ]
            ]
        ];
    }

}