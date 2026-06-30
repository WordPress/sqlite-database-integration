<?php
/**
 * Generate the lexer's token data block (in src/class-wp-mysql-lexer.php).
 *
 * The block is spliced between the "generated token data" markers at the top of
 * the WP_MySQL_Lexer class. The lexer emits the grammar's own token numbers, so
 * all of its token-level data is derived from the MySQL sources and resolved by
 * NAME against the Bison automaton (never hard-coded, so it stays correct if
 * MySQL renumbers tokens):
 *
 *   - Named constants for the structural tokens the scanner code references
 *     (operators, punctuation, literals, and the few keywords with scanner
 *     logic of their own).
 *   - KEYWORDS — the keyword string => token number table, taken directly from
 *     MySQL's lex.h. Words declared there with a terminal that is not part of
 *     the grammar (hint-only keywords) are omitted and thus lex as identifiers,
 *     matching MySQL, which recognises them only inside optimizer hints.
 *   - FUNCTIONS — keyword strings declared with SYM_FN, which MySQL treats as
 *     keywords only when directly followed by a parenthesis.
 *
 * No number-to-name table is shipped: diagnostic token names are derived at
 * runtime by inverting KEYWORDS and reflecting the named constants (see
 * WP_MySQL_Lexer::get_token_name()).
 *
 * Usage: php generate-tokens.php <automaton.xml> <lex.h> <lexer.php>
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php generate-tokens.php <automaton.xml> <lex.h> <lexer.php>\n" );
	exit( 1 );
}
$xml_path   = $argv[1];
$lexh_path  = $argv[2];
$lexer_path = $argv[3];
$mysql_tag  = getenv( 'MYSQL_TAG' );
if ( false === $mysql_tag || '' === $mysql_tag ) {
	$mysql_tag = 'mysql-8.4.10';
}

// Bison terminal name => token-number, read straight from the automaton.
$terminals = array();
$reader    = new XMLReader();
$reader->open( $xml_path );
while ( $reader->read() ) {
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader API property.
	if ( XMLReader::ELEMENT === $reader->nodeType && 'terminal' === $reader->name ) {
		$terminals[ $reader->getAttribute( 'name' ) ] = (int) $reader->getAttribute( 'token-number' );
	}
}
$reader->close();

// A terminal the lexer depends on that doesn't resolve against this grammar
// would only surface at runtime, far from the cause — fail the build instead.
$bison_number = function ( $name ) use ( $terminals ) {
	if ( ! isset( $terminals[ $name ] ) ) {
		fwrite( STDERR, "error: Bison terminal '$name' not found in the automaton; the grammar bridge needs updating for this MySQL version.\n" );
		exit( 1 );
	}
	return $terminals[ $name ];
};

/*
 * Named constants: lexer constant name => Bison terminal name. Single-char
 * tokens use Bison's quoted-character names (e.g. "'('"); the rest are the
 * explicit %token names from sql_yacc.yy. These are the tokens the scanner
 * code refers to by name: operators and literals it recognises structurally,
 * and the few keywords with scanner logic of their own (the WITH ROLLUP
 * contraction and the HIGH_NOT_PRECEDENCE mode), plus the end markers.
 */
$constants = array(
	'OPEN_PAR_SYMBOL'                => "'('",
	'CLOSE_PAR_SYMBOL'               => "')'",
	'OPEN_CURLY_SYMBOL'              => "'{'",
	'CLOSE_CURLY_SYMBOL'             => "'}'",
	'AT_SIGN_SYMBOL'                 => "'@'",
	'COLON_SYMBOL'                   => "':'",
	'COMMA_SYMBOL'                   => "','",
	'DOT_SYMBOL'                     => "'.'",
	'SEMICOLON_SYMBOL'               => "';'",
	'BITWISE_AND_OPERATOR'           => "'&'",
	'BITWISE_NOT_OPERATOR'           => "'~'",
	'BITWISE_OR_OPERATOR'            => "'|'",
	'BITWISE_XOR_OPERATOR'           => "'^'",
	'DIV_OPERATOR'                   => "'/'",
	'MINUS_OPERATOR'                 => "'-'",
	'MOD_OPERATOR'                   => "'%'",
	'MULT_OPERATOR'                  => "'*'",
	'PLUS_OPERATOR'                  => "'+'",
	'LOGICAL_NOT_OPERATOR'           => "'!'",
	'EQUAL_OPERATOR'                 => 'EQ',
	'NULL_SAFE_EQUAL_OPERATOR'       => 'EQUAL_SYM',
	'GREATER_OR_EQUAL_OPERATOR'      => 'GE',
	'GREATER_THAN_OPERATOR'          => 'GT_SYM',
	'LESS_OR_EQUAL_OPERATOR'         => 'LE',
	'LESS_THAN_OPERATOR'             => 'LT',
	'NOT_EQUAL_OPERATOR'             => 'NE',
	'ASSIGN_OPERATOR'                => 'SET_VAR',
	'LOGICAL_AND_OPERATOR'           => 'AND_AND_SYM',
	'LOGICAL_OR_OPERATOR'            => 'OR2_SYM',
	'CONCAT_PIPES_SYMBOL'            => 'OR_OR_SYM',
	'SHIFT_LEFT_OPERATOR'            => 'SHIFT_LEFT',
	'SHIFT_RIGHT_OPERATOR'           => 'SHIFT_RIGHT',
	'JSON_SEPARATOR_SYMBOL'          => 'JSON_SEPARATOR_SYM',
	'JSON_UNQUOTED_SEPARATOR_SYMBOL' => 'JSON_UNQUOTED_SEPARATOR_SYM',
	'PARAM_MARKER'                   => 'PARAM_MARKER',
	'IDENTIFIER'                     => 'IDENT',
	'BACK_TICK_QUOTED_ID'            => 'IDENT_QUOTED',
	'SINGLE_QUOTED_TEXT'             => 'TEXT_STRING',
	'DOUBLE_QUOTED_TEXT'             => 'TEXT_STRING',
	'NCHAR_TEXT'                     => 'NCHAR_STRING',
	'UNDERSCORE_CHARSET'             => 'UNDERSCORE_CHARSET',
	'BIN_NUMBER'                     => 'BIN_NUM',
	'HEX_NUMBER'                     => 'HEX_NUM',
	'INT_NUMBER'                     => 'NUM',
	'LONG_NUMBER'                    => 'LONG_NUM',
	'ULONGLONG_NUMBER'               => 'ULONGLONG_NUM',
	'DECIMAL_NUMBER'                 => 'DECIMAL_NUM',
	'FLOAT_NUMBER'                   => 'FLOAT_NUM',
	'NULL2_SYMBOL'                   => 'NULL_SYM',
	'NOT_SYMBOL'                     => 'NOT_SYM',
	'NOT2_SYMBOL'                    => 'NOT2_SYM',
	'WITH_SYMBOL'                    => 'WITH',
	'ROLLUP_SYMBOL'                  => 'ROLLUP_SYM',
	'WITH_ROLLUP_SYMBOL'             => 'WITH_ROLLUP_SYM',
	'END_OF_INPUT'                   => 'END_OF_INPUT',
	'END_MARKER'                     => '$end',
);

$constant_values = array();
foreach ( $constants as $constant_name => $terminal_name ) {
	$constant_values[ $constant_name ] = $bison_number( $terminal_name );
}

/*
 * Keyword table: lex.h declares each keyword string with its Bison terminal
 * via SYM()/SYM_FN()/SYM_H()/SYM_HK() macros. The main table precedes the
 * hint-keyword table, so first declaration wins for words in both (e.g. INDEX).
 */
$lexh = file_get_contents( $lexh_path );
preg_match_all( '/\{(SYM[A-Z_]*)\("((?:[^"\\\\]|\\\\.)*)",\s*([A-Za-z0-9_]+)\)\}/', $lexh, $matches, PREG_SET_ORDER );

$keywords  = array();
$functions = array();
foreach ( $matches as $match ) {
	$keyword       = strtoupper( stripcslashes( $match[2] ) );
	$terminal_name = $match[3];
	if ( isset( $keywords[ $keyword ] ) || ! isset( $terminals[ $terminal_name ] ) ) {
		continue;   // Later duplicate, or a hint-only terminal absent from the grammar.
	}
	$keywords[ $keyword ] = $terminals[ $terminal_name ];
	if ( 'SYM_FN' === $match[1] ) {
		$functions[ $keyword ] = true;
	}
}
ksort( $keywords, SORT_STRING );
ksort( $functions, SORT_STRING );

/*
 * Render an associative "keyword => value" map as a single-line array literal.
 * The generated block is wrapped in phpcs:disable, so the two large tables can
 * each stay on one line and keep the lexer file compact.
 */
$render_keyword_table = function ( array $map ) {
	if ( empty( $map ) ) {
		return 'array()';
	}
	$entries = array();
	foreach ( $map as $keyword => $value ) {
		$entries[] = var_export( (string) $keyword, true ) . ' => ' . var_export( $value, true );
	}
	return 'array( ' . implode( ', ', $entries ) . ' )';
};

// Render the generated block as class members at one indentation level. The
// body conforms to WordPress Coding Standards; the phpcs:disable is kept only as
// the customary guard for a generated region.
$max_constant = max( array_map( 'strlen', array_keys( $constant_values ) ) );

$lines   = array();
$lines[] = "\t// BEGIN: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS";
$lines[] = "\t// This block is auto-generated from MySQL $mysql_tag. DO NOT EDIT!";
$lines[] = "\t// phpcs:disable";
foreach ( $constant_values as $constant_name => $number ) {
	$lines[] = "\tconst " . str_pad( $constant_name, $max_constant ) . " = $number;";
}
$lines[] = '';
$lines[] = "\tconst KEYWORDS = " . $render_keyword_table( $keywords ) . ';';
$lines[] = '';
$lines[] = "\tconst FUNCTIONS = " . $render_keyword_table( $functions ) . ';';
$lines[] = "\t// phpcs:enable";
$lines[] = "\t// END: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS";
$block   = implode( "\n", $lines );

// Splice the block between the markers at the top of the lexer class.
$lexer  = file_get_contents( $lexer_path );
$marker = '/\t\/\/ BEGIN: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS.*?\t\/\/ END: AUTO-GENERATED MYSQL GRAMMAR SYMBOLS/s';
if ( ! preg_match( $marker, $lexer ) ) {
	fwrite( STDERR, "error: generated token data markers not found in $lexer_path.\n" );
	exit( 1 );
}
$lexer = preg_replace( $marker, addcslashes( $block, '\\$' ), $lexer, 1 );
file_put_contents( $lexer_path, $lexer );

fwrite(
	STDERR,
	sprintf(
		"constants=%d keywords=%d functions=%d\n",
		count( $constant_values ),
		count( $keywords ),
		count( $functions )
	)
);
echo 'Token data block written to ' . $lexer_path . "\n";
