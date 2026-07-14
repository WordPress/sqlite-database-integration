<?php

/**
 * This script runs the MySQL lexer & parser on a single query and dumps its AST.
 * It is useful for testing and debugging the lexer and parser functionality.
 *
 * Usage: php dump-ast.php "SELECT 1"
 */

// throw exception if anything fails
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/../../src/load.php';

$query  = $argv[1] ?? 'SELECT 1';
$lexer  = new WP_MySQL_Lexer( $query );
$tokens = $lexer->remaining_tokens();

echo "Tokens:\n";
foreach ( $tokens as $token ) {
	echo $token, "\n";
}

$parser = WP_MySQL_Parser_Factory::create_parser();
$ast    = $parser->parse( $tokens );

echo "\n\n";
echo "AST:\n";
if ( null === $ast ) {
	echo "PARSE ERROR\n";
	exit( 1 );
}

function dump_node( $node, int $depth = 0 ): void {
	$pad = str_repeat( '  ', $depth );
	if ( $node instanceof WP_Parser_Node ) {
		echo $pad, $node->rule_name, "\n";
		foreach ( $node->get_children() as $child ) {
			dump_node( $child, $depth + 1 );
		}
	} else {
		echo $pad, $node->get_name(), '<', $node->get_value(), ">\n";
	}
}
dump_node( $ast );
