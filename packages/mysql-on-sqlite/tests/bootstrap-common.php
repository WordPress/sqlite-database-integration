<?php

require_once __DIR__ . '/../src/load.php';

if ( '1' === getenv( 'WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION' ) ) {
	require_once __DIR__ . '/tools/verify-native-parser-extension.php';
}

if ( ! function_exists( 'wp_postgresql_tests_create_pgsql_pdo' ) ) {
	/**
	 * Create an isolated real PostgreSQL PDO for PostgreSQL test harnesses.
	 *
	 * @return PDO Real PostgreSQL PDO with an isolated search_path schema.
	 */
	function wp_postgresql_tests_create_pgsql_pdo(): PDO {
		$dsn = getenv( 'PGSQL_TEST_DSN' );
		if ( false === $dsn || '' === $dsn ) {
			throw new RuntimeException( 'Set PGSQL_TEST_DSN to run PostgreSQL-backed tests.' );
		}

		$user     = getenv( 'PGSQL_TEST_USER' );
		$password = getenv( 'PGSQL_TEST_PASSWORD' );
		$pdo      = new PDO(
			$dsn,
			false === $user ? null : $user,
			false === $password ? null : $password
		);
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		if ( 'pgsql' !== $pdo->getAttribute( PDO::ATTR_DRIVER_NAME ) ) {
			throw new RuntimeException( 'PGSQL_TEST_DSN must use the pgsql PDO driver.' );
		}

		$schema     = 'wp_pg_test_' . strtolower( bin2hex( random_bytes( 8 ) ) );
		$schema_sql = WP_PostgreSQL_Connection::quote_identifier_value( $schema );

		$pdo->exec( 'CREATE SCHEMA ' . $schema_sql );
		$pdo->exec( 'SET search_path TO ' . $schema_sql . ', public' );

		register_shutdown_function(
			static function () use ( $pdo, $schema_sql ): void {
				try {
					if ( $pdo->inTransaction() ) {
						$pdo->rollBack();
					}

					$pdo->exec( 'DROP SCHEMA IF EXISTS ' . $schema_sql . ' CASCADE' );
				} catch ( Throwable $e ) {
					// Cleanup should not mask the isolated script result.
				}
			}
		);

		return $pdo;
	}
}

// Configure the test environment.
error_reporting( E_ALL );

// Polyfill WPDB globals.
$GLOBALS['table_prefix'] = 'wptests_';
$GLOBALS['wpdb']         = new class() {
	public function set_prefix( string $prefix ): void {}
};

/**
 * Polyfills for WordPress functions
 */
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Polyfill the do_action function.
	 */
	function do_action() {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Polyfill the apply_filters function.
	 *
	 * @param string $tag The filter name.
	 * @param mixed  $value The value to filter.
	 * @param mixed  ...$args Additional arguments to pass to the filter.
	 *
	 * @return mixed Returns $value.
	 */
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

if ( extension_loaded( 'mbstring' ) ) {

	if ( ! function_exists( 'mb_str_starts_with' ) ) {
		/**
		 * Polyfill for mb_str_starts_with.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_starts_with( string $haystack, string $needle ) {
			return empty( $needle ) || 0 === mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_contains' ) ) {
		/**
		 * Polyfill for mb_str_contains.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_contains( string $haystack, string $needle ) {
			return empty( $needle ) || false !== mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_ends_with' ) ) {
		/**
		 * Polyfill for mb_str_ends_with.
		 *
		 * @param string $haystack The string to search in.
		 * @param string $needle   The string to search for.
		 *
		 * @return bool
		 */
		function mb_str_ends_with( string $haystack, string $needle ) {
			return empty( $needle ) || mb_substr( $haystack, - mb_strlen( $needle ) ) === $needle;
		}
	}
}
