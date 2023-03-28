<?php

require_once __DIR__ . '/wp-sqlite-schema.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-query-rewriter.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-lexer.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-token.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';
require_once __DIR__ . '/../wp-includes/sqlite/class-wp-sqlite-translator.php';

/**
 * Polyfills for php 8 functions
 */

/**
 * @see https://www.php.net/manual/en/function.str-starts-with
 */
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( string $haystack, string $needle ) {
		return empty( $needle ) || 0 === strpos( $haystack, $needle );
	}
}

/**
 * @see https://www.php.net/manual/en/function.str-contains
 */
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( string $haystack, string $needle ) {
		return empty( $needle ) || false !== strpos( $haystack, $needle );
	}
}

/**
 * @see https://www.php.net/manual/en/function.str-ends-with
 */
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( string $haystack, string $needle ) {
		return empty( $needle ) || $needle === substr( $haystack, -strlen( $needle ) );
	}
}
if ( extension_loaded( 'mbstring' ) ) {

	if ( ! function_exists( 'mb_str_starts_with' ) ) {
		function mb_str_starts_with( string $haystack, string $needle ) {
			return empty( $needle ) || 0 === mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_contains' ) ) {
		function mb_str_contains( string $haystack, string $needle ) {
			return empty( $needle ) || false !== mb_strpos( $haystack, $needle );
		}
	}

	if ( ! function_exists( 'mb_str_ends_with' ) ) {
		function mb_str_ends_with( string $haystack, string $needle ) {
			return empty( $needle ) || $needle = mb_substr( $haystack, - mb_strlen( $needle ) );
		}
	}
}
