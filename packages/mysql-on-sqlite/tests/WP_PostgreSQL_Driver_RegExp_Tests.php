<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PostgreSQL REGEXP query translation.
 */
class WP_PostgreSQL_Driver_RegExp_Tests extends TestCase {
	/**
	 * Tests WordPress options REGEXP deletes are translated before execution.
	 */
	public function test_wordpress_options_regexp_delete_is_translated_to_postgresql_regex_operator(): void {
		$logged_queries = array();
		$connection     = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		$connection->set_query_logger(
			static function ( string $sql, array $params ) use ( &$logged_queries ): void {
				$logged_queries[] = array(
					'sql'    => $sql,
					'params' => $params,
				);
			}
		);

		$driver = new WP_PostgreSQL_Driver( $connection, 'wptests' );
		$query  = "DELETE FROM `wptests_options` WHERE `option_name` REGEXP '^_transient_feed_'";

		try {
			$driver->query( $query );
			$this->fail( 'SQLite unexpectedly accepted the PostgreSQL regular expression operator.' );
		} catch ( PDOException $exception ) {
			$this->assertStringContainsString( 'near "~"', $exception->getMessage() );
		}

		$this->assertSame( $query, $driver->get_last_mysql_query() );
		$this->assertSame( array(), $driver->get_last_postgresql_queries() );
		$this->assertSame(
			array(
				'sql'    => 'DELETE FROM "wptests_options" WHERE "option_name" ~ \'^_transient_feed_\'',
				'params' => array(),
			),
			end( $logged_queries )
		);
	}
}
