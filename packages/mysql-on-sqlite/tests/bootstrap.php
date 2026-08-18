<?php

require_once __DIR__ . '/wp-sqlite-schema.php';
require_once __DIR__ . '/../vendor/autoload.php';

// When on an older SQLite version, enable unsafe backward compatibility.
$sqlite_version = ( new PDO( 'sqlite::memory:' ) )->query( 'SELECT SQLITE_VERSION();' )->fetch()[0];
if ( version_compare( $sqlite_version, WP_MySQL_On_SQLite::MINIMUM_SQLITE_VERSION, '<' ) ) {
	define( 'WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS', true );
}

if ( '1' === getenv( 'WP_SQLITE_REQUIRE_NATIVE_PARSER_EXTENSION' ) ) {
	require_once __DIR__ . '/tools/verify-native-parser-extension.php';
}

// Configure the test environment.
error_reporting( E_ALL );
define( 'FQDB', ':memory:' );
define( 'FQDBDIR', __DIR__ . '/../testdb' );

// Polyfill WPDB globals.
$GLOBALS['table_prefix'] = 'wptests_';
$GLOBALS['wpdb']         = new class() {
	public $blogs;

	public function set_prefix( string $prefix ) {
		if ( preg_match( '|[^a-z0-9_]|i', $prefix ) ) {
			return new WP_Error( 'invalid_db_prefix', 'Invalid database prefix' );
		}

		$this->blogs = $prefix . 'blogs';
		return $prefix;
	}
};

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;

		public function __construct( $code, $message ) {
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

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
