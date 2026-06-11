<?php
/**
 * Generate the grammar token interface (src/grammar/tokens.php).
 *
 * The lexer emits the grammar's own token numbers, so all of its token-level
 * data is derived from the MySQL sources and resolved by NAME against the Bison
 * automaton (never hard-coded, so it stays correct if MySQL renumbers tokens):
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
 *   - TOKEN_NAMES — token number => terminal name, for diagnostics.
 *
 * Usage: php generate-tokens.php <automaton.xml> <lex.h> <output.php>
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php generate-tokens.php <automaton.xml> <lex.h> <output.php>\n" );
	exit( 1 );
}
$xml_path    = $argv[1];
$lexh_path   = $argv[2];
$output_path = $argv[3];
$mysql_tag   = getenv( 'MYSQL_TAG' );
if ( false === $mysql_tag || '' === $mysql_tag ) {
	$mysql_tag = 'mysql-8.4.3';
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
ksort( $keywords );
ksort( $functions );

// Diagnostics: every terminal's number => name.
$names = array_flip( $terminals );
ksort( $names );

// Render the interface.
$lines   = array();
$lines[] = '<?php';
$lines[] = '// THIS FILE IS GENERATED by tools/generate-tokens.php. DO NOT EDIT.';
$lines[] = "// MySQL grammar token constants and keyword table, from $mysql_tag.";
$lines[] = '// phpcs:disable';
$lines[] = '';
$lines[] = '/**';
$lines[] = ' * Token-level data of the MySQL grammar.';
$lines[] = ' *';
$lines[] = ' * All token numbers are the Bison token numbers of the official MySQL grammar';
$lines[] = ' * (sql_yacc.yy); KEYWORDS and FUNCTIONS come from MySQL\'s keyword table (lex.h).';
$lines[] = ' */';
$lines[] = 'interface WP_MySQL_Tokens {';
foreach ( $constant_values as $constant_name => $number ) {
	$lines[] = "\tconst $constant_name = $number;";
}
$lines[] = '';
$lines[] = "\tconst KEYWORDS = " . str_replace( "\n", "\n\t", var_export( $keywords, true ) ) . ';';
$lines[] = '';
$lines[] = "\tconst FUNCTIONS = " . str_replace( "\n", "\n\t", var_export( $functions, true ) ) . ';';
$lines[] = '';
$lines[] = "\tconst TOKEN_NAMES = " . str_replace( "\n", "\n\t", var_export( $names, true ) ) . ';';
$lines[] = '}';
file_put_contents( $output_path, implode( "\n", $lines ) . "\n" );

fwrite(
	STDERR,
	sprintf(
		"constants=%d keywords=%d functions=%d names=%d\n",
		count( $constant_values ),
		count( $keywords ),
		count( $functions ),
		count( $names )
	)
);
echo round( filesize( $output_path ) / 1024 ) . " KB written to $output_path\n";
