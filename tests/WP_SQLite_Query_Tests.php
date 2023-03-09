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
	}

	public function testSelectExpiredTransients () {
		$q = <<<'QUERY'
SELECT a.option_name, b.option_value
  FROM wp_options a
  LEFT JOIN wp_options b
       ON b.option_name =
           CONCAT( CASE WHEN a.option_name LIKE '_site_transient_%' THEN '_site_transient_timeout_' ELSE '_transient_timeout_' END ,
                    SUBSTRING(a.option_name, CHAR_LENGTH( CASE WHEN a.option_name LIKE '_site_transient_%' THEN '_site_transient_' ELSE '_transient_' END ) + 1) )
  WHERE (a.option_name LIKE '_transient_%' OR a.option_name LIKE '_site_transient_%')
        AND a.option_name NOT LIKE '%_transient_timeout_%'
    AND b.option_value < UNIX_TIMESTAMP()
QUERY;

		$this->assertQuery( $q );

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

}
