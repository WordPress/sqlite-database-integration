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
				'sql'    => 'DELETE FROM "wptests_options" WHERE "option_name" ~* \'^_transient_feed_\'',
				'params' => array(),
			),
			end( $logged_queries )
		);
	}

	/**
	 * Tests REGEXP, RLIKE, and NOT REGEXP predicates use case-insensitive PostgreSQL regex operators.
	 */
	public function test_regexp_predicates_are_translated_to_postgresql_case_insensitive_regex_operators(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			"SELECT * FROM wptests_postmeta WHERE meta_key ~* '^foo'",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT * FROM wptests_postmeta WHERE meta_key REGEXP '^foo'"
			)
		);
		$this->assertSame(
			"SELECT * FROM wptests_postmeta WHERE meta_key !~* '^foo'",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT * FROM wptests_postmeta WHERE meta_key NOT REGEXP '^foo'"
			)
		);
		$this->assertSame(
			"SELECT * FROM wptests_postmeta WHERE meta_key ~* '^foo'",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT * FROM wptests_postmeta WHERE meta_key RLIKE '^foo'"
			)
		);
	}

	/**
	 * Tests default REGEXP collation behavior is represented by case-insensitive operators.
	 */
	public function test_regexp_predicates_match_mysql_case_insensitive_collation_shape(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			"SELECT 'rss_123' ~* '^RSS_.+$' AS is_match",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT 'rss_123' REGEXP '^RSS_.+$' AS is_match"
			)
		);
		$this->assertSame(
			"SELECT 'rss_123' !~* '^RSS_.+$' AS is_not_match",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT 'rss_123' NOT REGEXP '^RSS_.+$' AS is_not_match"
			)
		);
		$this->assertSame(
			"SELECT 'rss_123' ~* '^RSS_.+$' AS is_match",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT 'rss_123' RLIKE '^RSS_.+$' AS is_match"
			)
		);
	}

	/**
	 * Tests lower-case RLIKE predicates and qualified identifiers are translated.
	 */
	public function test_lowercase_rlike_predicate_with_qualified_identifier_is_translated(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			"SELECT * FROM wptests_posts WHERE wptests_posts.\"ID\" ~* '^[0-9]+$'",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT * FROM wptests_posts WHERE wptests_posts.ID rlike '^[0-9]+$'"
			)
		);
	}

	/**
	 * Tests REGEXP-like text inside string literals is not rewritten.
	 */
	public function test_regexp_rewrite_does_not_replace_string_literals(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			"SELECT 'REGEXP', 'RLIKE', 'NOT REGEXP' AS literal_value",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT 'REGEXP', 'RLIKE', 'NOT REGEXP' AS literal_value"
			)
		);
	}

	/**
	 * Tests unsupported REGEXP BINARY predicates fall through visibly.
	 */
	public function test_regexp_binary_predicate_is_not_silently_remapped(): void {
		$driver = $this->create_driver();

		$this->assertSame(
			"SELECT * FROM wptests_postmeta WHERE meta_key REGEXP BINARY '^foo'",
			$this->translate_driver_query_with_private_method(
				$driver,
				'translate_mysql_compatible_query',
				"SELECT * FROM wptests_postmeta WHERE meta_key REGEXP BINARY '^foo'"
			)
		);
	}

	/**
	 * Creates a PostgreSQL driver backed by an injected in-memory PDO.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	private function create_driver(): WP_PostgreSQL_Driver {
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );
		return new WP_PostgreSQL_Driver( $connection, 'wptests' );
	}

	/**
	 * Translate a query by calling a private driver translator.
	 *
	 * @param WP_PostgreSQL_Driver $driver      Driver under test.
	 * @param string               $method_name Private driver method name.
	 * @param string               $query       MySQL query.
	 * @return string|null PostgreSQL SQL, or null when unsupported.
	 */
	private function translate_driver_query_with_private_method( WP_PostgreSQL_Driver $driver, string $method_name, string $query ): ?string {
		$translator = Closure::bind(
			function ( string $bound_method_name, string $bound_query ): ?string {
				return $this->$bound_method_name( $bound_query );
			},
			$driver,
			WP_PostgreSQL_Driver::class
		);

		return $translator( $method_name, $query );
	}
}
