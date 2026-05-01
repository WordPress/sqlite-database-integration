<?php

require_once __DIR__ . '/wp-sqlite-schema.php';
require_once __DIR__ . '/../src/load.php';

// When on an older SQLite version, enable unsafe back compatibility.
$sqlite_version = ( new PDO( 'sqlite::memory:' ) )->query( 'SELECT SQLITE_VERSION();' )->fetch()[0];
if ( version_compare( $sqlite_version, WP_PDO_MySQL_On_SQLite::MINIMUM_SQLITE_VERSION, '<' ) ) {
	define( 'WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS', true );
}

if ( '1' === getenv( 'WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION' ) ) {
	if ( ! class_exists( 'WP_MySQL_Native_Lexer', false ) || ! class_exists( 'WP_MySQL_Native_Parser', false ) ) {
		fwrite( STDERR, "Native MySQL parser extension is required for this PHPUnit run.\n" );
		exit( 1 );
	}

	$native_parser_lexer = new WP_MySQL_Lexer( 'SELECT 1' );
	if ( ! ( $native_parser_lexer instanceof WP_MySQL_Native_Lexer ) ) {
		fwrite( STDERR, "WP_MySQL_Lexer did not resolve to the native implementation.\n" );
		exit( 1 );
	}

	$native_parser_tokens     = $native_parser_lexer->native_token_stream();
	$native_parser_rules      = include __DIR__ . '/../src/mysql/mysql-grammar.php';
	$native_parser_grammar    = new WP_Parser_Grammar( $native_parser_rules );
	$native_parser            = new WP_MySQL_Parser( $native_parser_grammar, $native_parser_tokens );
	$native_parser_reflection = new ReflectionObject( $native_parser );
	if ( ! $native_parser_reflection->hasProperty( 'native' ) ) {
		fwrite( STDERR, "WP_MySQL_Parser did not create a native parser delegate.\n" );
		exit( 1 );
	}
	$native_parser_property = $native_parser_reflection->getProperty( 'native' );
	$native_parser_property->setAccessible( true );
	if ( ! ( $native_parser_property->getValue( $native_parser ) instanceof WP_MySQL_Native_Parser ) ) {
		fwrite( STDERR, "WP_MySQL_Parser did not create a native parser delegate.\n" );
		exit( 1 );
	}

	$native_parser_ast = $native_parser->parse();
	if ( ! ( $native_parser_ast instanceof WP_MySQL_Native_Parser_Node ) ) {
		fwrite( STDERR, "Native parser did not produce a native-backed AST.\n" );
		exit( 1 );
	}

	$native_parser_driver            = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
	$native_parser_driver_parser     = $native_parser_driver->create_parser( 'SELECT 1' );
	$native_parser_driver_reflection = new ReflectionObject( $native_parser_driver_parser );
	if ( ! $native_parser_driver_reflection->hasProperty( 'native' ) ) {
		fwrite( STDERR, "WP_PDO_MySQL_On_SQLite did not create a native parser delegate.\n" );
		exit( 1 );
	}
	$native_parser_driver_property = $native_parser_driver_reflection->getProperty( 'native' );
	$native_parser_driver_property->setAccessible( true );
	if ( ! ( $native_parser_driver_property->getValue( $native_parser_driver_parser ) instanceof WP_MySQL_Native_Parser ) ) {
		fwrite( STDERR, "WP_PDO_MySQL_On_SQLite did not create a native parser delegate.\n" );
		exit( 1 );
	}

	$native_parser_driver_parser->next_query();
	$native_parser_driver_ast = $native_parser_driver_parser->get_query_ast();
	if ( ! ( $native_parser_driver_ast instanceof WP_MySQL_Native_Parser_Node ) ) {
		fwrite( STDERR, "WP_PDO_MySQL_On_SQLite did not produce a native-backed AST.\n" );
		exit( 1 );
	}

	$native_parser_driver_child = $native_parser_driver_ast->get_first_child_node();
	if ( ! ( $native_parser_driver_child instanceof WP_MySQL_Native_Parser_Node ) ) {
		fwrite( STDERR, "WP_PDO_MySQL_On_SQLite did not produce native-backed child AST nodes.\n" );
		exit( 1 );
	}

	unset(
		$native_parser_ast,
		$native_parser,
		$native_parser_grammar,
		$native_parser_rules,
		$native_parser_tokens,
		$native_parser_lexer,
		$native_parser_driver,
		$native_parser_driver_parser,
		$native_parser_reflection,
		$native_parser_property,
		$native_parser_driver_reflection,
		$native_parser_driver_property,
		$native_parser_driver_ast,
		$native_parser_driver_child
	);
}

// Configure the test environment.
error_reporting( E_ALL );
define( 'FQDB', ':memory:' );
define( 'FQDBDIR', __DIR__ . '/../testdb' );

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
			// phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			return empty( $needle ) || $needle = mb_substr( $haystack, - mb_strlen( $needle ) );
		}
	}
}
