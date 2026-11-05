<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/class-query-corpus-sql-modes.php';

/**
 * Tests for following the SQL modes of the sessions in MySQL server test files.
 */
class WP_MySQL_Query_Corpus_SQL_Modes_Tests extends TestCase {
	/**
	 * @dataProvider data_queries
	 */
	public function test_queries_change_the_modes( array $queries, string $expected ): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		foreach ( $queries as $query ) {
			$sql_modes->run_query( $query );
		}
		$this->assertSame( $expected, $sql_modes->get_modes() );
	}

	public function data_queries(): array {
		return array(
			// The value can be a string or a word, and the modes are sorted.
			'no query'                            => array( array(), '' ),
			'single-quoted string'                => array( array( "SET sql_mode = 'ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'double-quoted string'                => array( array( 'SET sql_mode = "ANSI_QUOTES"' ), 'ANSI_QUOTES' ),
			'word'                                => array( array( 'set sql_mode=pipes_as_concat' ), 'PIPES_AS_CONCAT' ),
			'several modes'                       => array( array( "SET sql_mode = 'PIPES_AS_CONCAT,ANSI_QUOTES'" ), 'ANSI_QUOTES,PIPES_AS_CONCAT' ),
			'spaces in a string'                  => array( array( "SET sql_mode = 'PIPES_AS_CONCAT, ANSI_QUOTES'" ), 'ANSI_QUOTES,PIPES_AS_CONCAT' ),
			'every lexer mode'                    => array(
				array( "SET sql_mode = 'PIPES_AS_CONCAT,NO_BACKSLASH_ESCAPES,IGNORE_SPACE,HIGH_NOT_PRECEDENCE,ANSI_QUOTES'" ),
				'ANSI_QUOTES,HIGH_NOT_PRECEDENCE,IGNORE_SPACE,NO_BACKSLASH_ESCAPES,PIPES_AS_CONCAT',
			),
			'modes that the lexer ignores'        => array( array( "SET sql_mode = 'TRADITIONAL,ANSI_QUOTES,NO_ZERO_DATE'" ), 'ANSI_QUOTES' ),
			'the ANSI mode'                       => array( array( "set sql_mode='ansi'" ), 'ANSI_QUOTES,IGNORE_SPACE,PIPES_AS_CONCAT' ),
			'a word for the ANSI mode'            => array( array( 'set sql_mode=ansi' ), 'ANSI_QUOTES,IGNORE_SPACE,PIPES_AS_CONCAT' ),

			// The session variable has several spellings.
			'SESSION'                             => array( array( "SET SESSION sql_mode='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'LOCAL'                               => array( array( "SET LOCAL sql_mode='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'@@'                                  => array( array( "SET @@sql_mode='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'@@session.'                          => array( array( "SET @@session.sql_mode='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'@@local.'                            => array( array( "SET @@local.sql_mode='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'uppercase name'                      => array( array( "SET @@SQL_MODE='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			':='                                  => array( array( "SET sql_mode:='ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'line breaks'                         => array( array( "SET\nsql_mode\n=\n'ANSI_QUOTES'\n" ), 'ANSI_QUOTES' ),

			// A later assignment replaces the modes.
			'empty string'                        => array( array( "SET sql_mode = 'ANSI_QUOTES'", "SET sql_mode = ''" ), '' ),
			'zero'                                => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = 0' ), '' ),
			'another mode'                        => array( array( "SET sql_mode = 'ANSI_QUOTES'", "SET sql_mode = 'IGNORE_SPACE'" ), 'IGNORE_SPACE' ),
			'a mode that the lexer ignores'       => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = traditional' ), '' ),
			'DEFAULT'                             => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = DEFAULT' ), '' ),

			// An expression can edit the current modes.
			'CONCAT'                              => array( array( "SET sql_mode = 'IGNORE_SPACE'", "SET sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')" ), 'ANSI_QUOTES,IGNORE_SPACE' ),
			'CONCAT in a subquery'                => array( array( "SET sql_mode=(SELECT CONCAT(@@sql_mode, ',PIPES_AS_CONCAT'))" ), 'PIPES_AS_CONCAT' ),
			'sys.LIST_ADD'                        => array( array( "SET sql_mode = sys.LIST_ADD(@@sql_mode, 'ANSI_QUOTES')" ), 'ANSI_QUOTES' ),
			'sys.LIST_DROP'                       => array( array( "SET sql_mode = 'ANSI_QUOTES,IGNORE_SPACE'", "SET sql_mode = sys.LIST_DROP(@@sql_mode, 'ANSI_QUOTES')" ), 'IGNORE_SPACE' ),
			'adding a mode twice'                 => array( array( "SET sql_mode = 'ANSI_QUOTES'", "SET sql_mode = sys.LIST_ADD(@@sql_mode, 'ANSI_QUOTES')" ), 'ANSI_QUOTES' ),

			// A user variable can save and restore the modes.
			'SET saves'                           => array(
				array( "SET sql_mode = 'ANSI_QUOTES'", 'SET @saved = @@sql_mode', "SET sql_mode = ''", 'SET sql_mode = @saved' ),
				'ANSI_QUOTES',
			),
			'SET saves @@session.'                => array(
				array( "SET sql_mode = 'ANSI_QUOTES'", 'SET @saved = @@session.sql_mode', "SET sql_mode = ''", 'SET sql_mode = @saved' ),
				'ANSI_QUOTES',
			),
			'SELECT INTO saves'                   => array(
				array( "SET sql_mode = 'ANSI_QUOTES'", 'SELECT @@sql_mode INTO @saved', "SET sql_mode = ''", 'SET sql_mode = @saved' ),
				'ANSI_QUOTES',
			),
			'SELECT INTO saves @@session.'        => array(
				array( "SET sql_mode = 'ANSI_QUOTES'", 'select @@session.sql_mode into @saved', "SET sql_mode = ''", 'SET sql_mode = @saved' ),
				'ANSI_QUOTES',
			),
			'variable names are case-insensitive' => array(
				array( "SET sql_mode = 'ANSI_QUOTES'", 'SET @Saved = @@sql_mode', "SET sql_mode = ''", 'SET sql_mode = @SAVED' ),
				'ANSI_QUOTES',
			),
			'one assignment saves, another sets'  => array(
				array( "SET sql_mode = 'IGNORE_SPACE'", "SET @saved = @@sql_mode, sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = @saved' ),
				'IGNORE_SPACE',
			),

			// Other assignments can be in the same statement.
			'an assignment after'                 => array( array( "SET sql_mode = 'ANSI_QUOTES', @a = 1" ), 'ANSI_QUOTES' ),
			'an assignment before'                => array( array( "SET @a = 1, sql_mode = 'ANSI_QUOTES'" ), 'ANSI_QUOTES' ),
			'a comma in a string'                 => array( array( "SET @a = 'b,sql_mode=ANSI_QUOTES', sql_mode = 'IGNORE_SPACE'" ), 'IGNORE_SPACE' ),
			'a comma in parentheses'              => array( array( "SET @a = CONCAT('b', 'c'), sql_mode = 'IGNORE_SPACE'" ), 'IGNORE_SPACE' ),

			// A query can have several statements.
			'several statements'                  => array( array( "SET sql_mode = 'ANSI_QUOTES'; SELECT 1; SET sql_mode = CONCAT(@@sql_mode, ',IGNORE_SPACE')" ), 'ANSI_QUOTES,IGNORE_SPACE' ),
			'an assignment before a routine'      => array( array( "SET sql_mode = 'ANSI_QUOTES';\nCREATE PROCEDURE p() BEGIN SELECT 1; END" ), 'ANSI_QUOTES' ),

			// A routine doesn't run when it's created.
			'a procedure body'                    => array( array( "CREATE PROCEDURE p() SET sql_mode = 'ANSI_QUOTES'" ), '' ),
			'a function body'                     => array( array( "CREATE FUNCTION f() RETURNS INT BEGIN SET sql_mode = 'ANSI_QUOTES'; RETURN 1; END" ), '' ),
			'a trigger body'                      => array( array( "CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET sql_mode = 'ANSI_QUOTES'" ), '' ),
			'an event body'                       => array( array( "CREATE EVENT e ON SCHEDULE EVERY 1 DAY DO SET sql_mode = 'ANSI_QUOTES'" ), '' ),
			'a body with a definer'               => array( array( "CREATE DEFINER = root@localhost PROCEDURE p() SET sql_mode = 'ANSI_QUOTES'" ), '' ),
			'a body in a later statement'         => array( array( "DROP PROCEDURE p; CREATE PROCEDURE p() BEGIN SELECT 1; SET sql_mode = 'ANSI_QUOTES'; END" ), '' ),

			// Other queries don't change the modes.
			'reading the modes'                   => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SELECT @@sql_mode' ), 'ANSI_QUOTES' ),
			'a column with that name'             => array( array( "SET sql_mode = 'ANSI_QUOTES'", "UPDATE t SET sql_mode = ''" ), 'ANSI_QUOTES' ),
			'another variable'                    => array( array( "SET sql_mode = 'ANSI_QUOTES'", "SET my_sql_mode = ''" ), 'ANSI_QUOTES' ),
			'a modes string in another query'     => array( array( "SELECT 'SET sql_mode = ANSI_QUOTES'" ), '' ),

			// A value that can't be evaluated keeps the modes.
			'an unknown variable'                 => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = @unknown' ), 'ANSI_QUOTES' ),
			'a number'                            => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = 2097152' ), 'ANSI_QUOTES' ),
			'an expression'                       => array( array( "SET sql_mode = 'ANSI_QUOTES'", 'SET sql_mode = 32+(65536*4)' ), 'ANSI_QUOTES' ),
		);
	}

	/**
	 * @dataProvider data_global_modes
	 */
	public function test_global_modes_apply_to_new_connections( string $assignment, string $current, string $connected ): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_query( "SET GLOBAL sql_mode = 'IGNORE_SPACE'" );
		$sql_modes->run_query( $assignment );
		$this->assertSame( $current, $sql_modes->get_modes(), 'The current session' );

		$sql_modes->run_command( 'connect (con1,localhost,root,,)' );
		$this->assertSame( $connected, $sql_modes->get_modes(), 'A new session' );
	}

	public function data_global_modes(): array {
		return array(
			'GLOBAL'                    => array( "SET GLOBAL sql_mode = 'ANSI_QUOTES'", '', 'ANSI_QUOTES' ),
			'@@global.'                 => array( "SET @@global.sql_mode = 'ANSI_QUOTES'", '', 'ANSI_QUOTES' ),
			'DEFAULT has no lexer mode' => array( 'SET GLOBAL sql_mode = DEFAULT', '', '' ),
			'a session DEFAULT'         => array( 'SET sql_mode = DEFAULT', 'IGNORE_SPACE', 'IGNORE_SPACE' ),
			'reading the global modes'  => array( 'SET sql_mode = @@global.sql_mode', 'IGNORE_SPACE', 'IGNORE_SPACE' ),
			'a session assignment'      => array( "SET sql_mode = 'ANSI_QUOTES'", 'ANSI_QUOTES', 'IGNORE_SPACE' ),
			'global from session modes' => array( "SET sql_mode = 'ANSI_QUOTES'; SET GLOBAL sql_mode = @@sql_mode", 'ANSI_QUOTES', 'ANSI_QUOTES' ),
		);
	}

	public function test_each_connection_has_its_own_modes(): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_query( "SET sql_mode = 'ANSI_QUOTES'" );

		$sql_modes->run_command( 'connect (con1,localhost,root,,)' );
		$this->assertSame( '', $sql_modes->get_modes() );
		$sql_modes->run_query( "SET sql_mode = 'IGNORE_SPACE'" );

		$sql_modes->run_command( 'connection default' );
		$this->assertSame( 'ANSI_QUOTES', $sql_modes->get_modes() );

		$sql_modes->run_command( 'connection con1' );
		$this->assertSame( 'IGNORE_SPACE', $sql_modes->get_modes() );
	}

	/**
	 * @dataProvider data_commands
	 */
	public function test_commands_select_connections( string $connect, string $connection ): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_command( $connect );
		$sql_modes->run_query( "SET sql_mode = 'ANSI_QUOTES'" );

		$sql_modes->run_command( 'connection default' );
		$this->assertSame( '', $sql_modes->get_modes(), $connect );

		$sql_modes->run_command( $connection );
		$this->assertSame( 'ANSI_QUOTES', $sql_modes->get_modes(), $connection );
	}

	public function data_commands(): array {
		return array(
			'mysqltest commands'   => array( 'connect (con1,localhost,root,,)', 'connection con1' ),
			'-- commands'          => array( '--connect (con1,localhost,root,,)', '--connection con1' ),
			'no space'             => array( '--connect(con1,localhost,root,,)', 'connection con1' ),
			'spaces and uppercase' => array( 'CONNECT ( con1 , localhost, root,,)', '--CONNECTION   con1  ' ),
			'a line break'         => array( "connect (con1,\nlocalhost,root,,)", 'connection con1' ),
		);
	}

	public function test_a_connection_that_the_file_did_not_open_has_the_global_modes(): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_query( "SET GLOBAL sql_mode = 'ANSI_QUOTES'" );
		$sql_modes->run_command( 'connection opened_in_an_include_file' );
		$this->assertSame( 'ANSI_QUOTES', $sql_modes->get_modes() );
	}

	public function test_opening_a_connection_again_starts_a_new_session(): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_command( 'connect (con1,localhost,root,,)' );
		$sql_modes->run_query( "SET sql_mode = 'ANSI_QUOTES'" );
		$sql_modes->run_command( 'connection default' );
		$sql_modes->run_command( 'connect (con1,localhost,root,,)' );
		$this->assertSame( '', $sql_modes->get_modes() );
	}

	public function test_other_commands_keep_the_connection(): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_query( "SET sql_mode = 'ANSI_QUOTES'" );
		foreach ( array( '--echo connection con1', '--disconnect con1', 'disconnect con1', '--source include/connect.inc' ) as $command ) {
			$sql_modes->run_command( $command );
			$this->assertSame( 'ANSI_QUOTES', $sql_modes->get_modes(), $command );
		}
	}

	/**
	 * @dataProvider data_session_resets
	 */
	public function test_session_resets_read_the_global_modes( string $command ): void {
		$sql_modes = new Query_Corpus_SQL_Modes();
		$sql_modes->run_query( "SET GLOBAL sql_mode = 'IGNORE_SPACE'" );
		$sql_modes->run_query( "SET sql_mode = 'ANSI_QUOTES'" );
		$sql_modes->run_command( $command );
		$this->assertSame( 'IGNORE_SPACE', $sql_modes->get_modes(), $command );
	}

	public function data_session_resets(): array {
		return array(
			'reset_connection'   => array( 'reset_connection' ),
			'--reset_connection' => array( '--reset_connection' ),
			'change_user'        => array( 'change_user root' ),
			'--change_user'      => array( '--change_user root,,test' ),
		);
	}
}
