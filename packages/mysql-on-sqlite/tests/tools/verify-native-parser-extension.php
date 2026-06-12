<?php

/**
 * Verify that the native MySQL parser extension is active and wired through
 * both the public parser API and the SQLite driver parser factory.
 */

require_once __DIR__ . '/../../src/load.php';

/**
 * Fail the native parser verification with a clear stderr message.
 *
 * @param string $message Failure message.
 */
function wp_sqlite_native_parser_verification_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Verify that a public parser wrapper delegates to the Rust parser instance.
 *
 * @param WP_MySQL_Parser $parser  Parser to inspect.
 * @param string          $context Failure context.
 */
function wp_sqlite_assert_native_parser_delegate( WP_MySQL_Parser $parser, string $context ): void {
	$reflection = new ReflectionObject( $parser );
	if ( ! $reflection->hasProperty( 'native' ) ) {
		wp_sqlite_native_parser_verification_fail( $context );
	}

	$native_property = $reflection->getProperty( 'native' );
	$native_property->setAccessible( true );
	if ( ! ( $native_property->getValue( $parser ) instanceof WP_MySQL_Native_Parser ) ) {
		wp_sqlite_native_parser_verification_fail( $context );
	}
}

/**
 * Run the native parser verification.
 */
function wp_sqlite_verify_native_parser_extension(): void {
	if ( ! class_exists( 'WP_MySQL_Native_Lexer', false ) || ! class_exists( 'WP_MySQL_Native_Parser', false ) ) {
		wp_sqlite_native_parser_verification_fail( 'Native MySQL parser extension is not loaded.' );
	}

	$lexer = new WP_MySQL_Lexer( 'SELECT ID, post_title FROM wp_posts WHERE ID IN (1, 2, 3)' );
	if ( ! ( $lexer instanceof WP_MySQL_Native_Lexer ) ) {
		wp_sqlite_native_parser_verification_fail( 'WP_MySQL_Lexer did not resolve to the native implementation.' );
	}

	$tokens  = $lexer->native_token_stream();
	$rules   = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
	$grammar = new WP_Parser_Grammar( $rules );
	$parser  = new WP_MySQL_Parser( $grammar, $tokens );
	wp_sqlite_assert_native_parser_delegate(
		$parser,
		'WP_MySQL_Parser did not create a native parser delegate.'
	);

	$parser_ast = $parser->parse();
	if ( ! ( $parser_ast instanceof WP_MySQL_Native_Parser_Node ) || 'query' !== $parser_ast->rule_name ) {
		wp_sqlite_native_parser_verification_fail( 'Native parser did not produce the expected query AST.' );
	}

	$driver = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
	$parser = $driver->create_parser( 'SELECT 1' );
	wp_sqlite_assert_native_parser_delegate(
		$parser,
		'WP_PDO_MySQL_On_SQLite did not create a native parser delegate.'
	);

	$parser->next_query();
	$ast = $parser->get_query_ast();
	if ( ! ( $ast instanceof WP_MySQL_Native_Parser_Node ) ) {
		wp_sqlite_native_parser_verification_fail( 'WP_PDO_MySQL_On_SQLite did not produce a native-backed AST.' );
	}

	$reflection = new ReflectionObject( $ast );
	if ( $reflection->hasProperty( 'native_ast' ) || $reflection->hasProperty( 'native_node_index' ) ) {
		wp_sqlite_native_parser_verification_fail( 'Native wrapper still stores Rust AST handle properties.' );
	}

	$first = $ast->get_first_child_node();
	if ( ! ( $first instanceof WP_MySQL_Native_Parser_Node ) ) {
		wp_sqlite_native_parser_verification_fail( 'Native wrapper did not return a native-backed child node.' );
	}

	if ( $first !== $ast->get_first_child_node() ) {
		wp_sqlite_native_parser_verification_fail( 'Native wrapper identity is not stable across reads.' );
	}

	$synthetic = new WP_Parser_Node( 0, 'synthetic' );
	$first->append_child( $synthetic );
	$same_first = $ast->get_first_child_node();
	if ( $same_first !== $first || ! in_array( $synthetic, $same_first->get_children(), true ) ) {
		wp_sqlite_native_parser_verification_fail( 'Materialized native wrapper was lost from the parent cache.' );
	}
}

wp_sqlite_verify_native_parser_extension();
