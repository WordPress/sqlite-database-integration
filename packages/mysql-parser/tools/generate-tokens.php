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
 *     logic of their own), named after their terminals in sql_yacc.yy.
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

// The tag of the grammar sources, recorded next to the automaton by fetch-mysql-grammar.sh.
$tag_path  = dirname( $xml_path ) . '/mysql-tag';
$mysql_tag = is_file( $tag_path ) ? trim( file_get_contents( $tag_path ) ) : '';
if ( '' === $mysql_tag ) {
	fwrite( STDERR, "error: MySQL tag not found in $tag_path; run fetch-mysql-grammar.sh first.\n" );
	exit( 1 );
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
		fwrite( STDERR, "error: Bison terminal '$name' not found in the automaton; the lexer token constants need updating for this MySQL version.\n" );
		exit( 1 );
	}
	return $terminals[ $name ];
};

/*
 * Token constants for the terminals the scanner code refers to by name:
 * operators and literals it recognises structurally, the few keywords with
 * scanner logic of their own ("\N", the WITH ROLLUP contraction, and the
 * HIGH_NOT_PRECEDENCE mode), and the end markers.
 *
 * Each constant is named after its %token in sql_yacc.yy. Single-character
 * terminals, which Bison names by the quoted character (e.g. "'('"), are
 * named after the character, and Bison's end-of-file terminal ("$end") is
 * named YYEOF, as in the C code that Bison generates.
 */
$character_names = array(
	'(' => 'LEFT_PAREN',
	')' => 'RIGHT_PAREN',
	'{' => 'LEFT_BRACE',
	'}' => 'RIGHT_BRACE',
	',' => 'COMMA',
	'.' => 'DOT',
	':' => 'COLON',
	';' => 'SEMICOLON',
	'@' => 'AT_SIGN',
	'+' => 'PLUS',
	'-' => 'MINUS',
	'*' => 'ASTERISK',
	'/' => 'SLASH',
	'%' => 'PERCENT',
	'&' => 'AMPERSAND',
	'|' => 'PIPE',
	'^' => 'CARET',
	'~' => 'TILDE',
	'!' => 'EXCLAMATION',
);

$terminal_names = array(
	'EQ',
	'EQUAL_SYM',
	'NE',
	'GE',
	'GT_SYM',
	'LE',
	'LT',
	'SET_VAR',
	'AND_AND_SYM',
	'OR2_SYM',
	'OR_OR_SYM',
	'SHIFT_LEFT',
	'SHIFT_RIGHT',
	'JSON_SEPARATOR_SYM',
	'JSON_UNQUOTED_SEPARATOR_SYM',
	'PARAM_MARKER',
	'IDENT',
	'IDENT_QUOTED',
	'TEXT_STRING',
	'NCHAR_STRING',
	'UNDERSCORE_CHARSET',
	'BIN_NUM',
	'HEX_NUM',
	'NUM',
	'LONG_NUM',
	'ULONGLONG_NUM',
	'DECIMAL_NUM',
	'FLOAT_NUM',
	'NULL_SYM',
	'NOT_SYM',
	'NOT2_SYM',
	'WITH',
	'ROLLUP_SYM',
	'WITH_ROLLUP_SYM',
	'END_OF_INPUT',
);

$constant_values = array();
foreach ( $character_names as $character => $constant_name ) {
	$constant_values[ $constant_name ] = $bison_number( "'$character'" );
}
foreach ( $terminal_names as $terminal_name ) {
	$constant_values[ $terminal_name ] = $bison_number( $terminal_name );
}
$constant_values['YYEOF'] = $bison_number( '$end' );

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
