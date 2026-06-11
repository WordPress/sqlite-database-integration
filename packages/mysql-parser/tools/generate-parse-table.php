<?php
/**
 * Generate the LALR(1) parse table from Bison's --xml automaton dump.
 *
 * Reads the automaton produced by `bison --xml` for MySQL's sql_yacc.yy and
 * emits the ACTION/GOTO tables as plain PHP arrays that WP_MySQL_Parser
 * executes. The MySQL grammar is unambiguous for LALR(1) (Bison resolves every
 * shift/reduce conflict by precedence and reports zero reduce/reduce
 * conflicts), so each (state, token) cell holds a single action.
 *
 * The table is kept small with three structural devices, all in plain PHP:
 *
 *   1. Per-state default reduce ('state_default'): most states reduce by the
 *      same production for nearly every lookahead; only differing cells are
 *      stored, as sparse token => action rows.
 *   2. Row sharing ('state_row'): states with identical cell sets point at a
 *      single shared row.
 *   3. Patch rows ('row_base'): the keyword-heavy rows are hundreds of cells
 *      each but nearly identical to one another (a keyword reduces by the same
 *      keyword-as-identifier production in every such state), so a row may be
 *      stored as a small patch over an earlier base row; the runtime applies
 *      patches with an array union at construction time.
 *
 *   GOTO targets cluster by nonterminal instead, so they are stored as a
 *   per-nonterminal default ('goto_default') plus sparse per-state exceptions
 *   ('goto_exceptions').
 *
 * Action codes (int): 0 = syntax error; 1..ns-1 = shift to that state;
 * ns = accept; < 0 = reduce by production -code.
 *
 * Usage: php generate-parse-table.php <automaton.xml> <output.php>
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php generate-parse-table.php <automaton.xml> <output.php>\n" );
	exit( 1 );
}
$xml_path    = $argv[1];
$output_path = $argv[2];
$mysql_tag   = getenv( 'MYSQL_TAG' );
if ( false === $mysql_tag || '' === $mysql_tag ) {
	$mysql_tag = 'mysql-8.4.3';
}

ini_set( 'memory_limit', '3G' );

$term_id  = array();   // Terminal name => token-number (the lexer's token ids).
$nt_id    = array();   // Nonterminal name => symbol-number.
$rule_lhs = array();   // Rule number => lhs name.
$rule_len = array();   // Rule number => rhs length.
$action   = array();   // State => [ token-number => code ] (>0 shift, <0 reduce, 'A' accept).
$goto     = array();   // State => [ symbol-number => target state ].
$adef     = array();   // State => default reduce code.
$ns       = 0;         // Number of states.

$reader = new XMLReader();
$reader->open( $xml_path );
$cur_state = -1;
$cur_rule  = -1;
$in_rhs    = false;
while ( $reader->read() ) {
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader API property.
	if ( XMLReader::ELEMENT === $reader->nodeType ) {
		switch ( $reader->name ) {
			case 'terminal':
				$term_id[ $reader->getAttribute( 'name' ) ] = (int) $reader->getAttribute( 'token-number' );
				break;
			case 'nonterminal':
				$nt_id[ $reader->getAttribute( 'name' ) ] = (int) $reader->getAttribute( 'symbol-number' );
				break;
			case 'rule':
				$cur_rule              = (int) $reader->getAttribute( 'number' );
				$rule_len[ $cur_rule ] = 0;
				break;
			case 'lhs':
				$rule_lhs[ $cur_rule ] = $reader->readString();
				break;
			case 'rhs':
				$in_rhs = true;
				break;
			case 'symbol':
				if ( $in_rhs ) {
					++$rule_len[ $cur_rule ];
				}
				break;
			case 'state':
				$cur_state = (int) $reader->getAttribute( 'number' );
				if ( $cur_state + 1 > $ns ) {
					$ns = $cur_state + 1;
				}
				break;
			case 'transition':
				$sym = $reader->getAttribute( 'symbol' );
				$to  = (int) $reader->getAttribute( 'state' );
				if ( 'shift' === $reader->getAttribute( 'type' ) ) {
					$action[ $cur_state ][ $term_id[ $sym ] ] = $to;   // Shift target state (> 0).
				} else {
					$goto[ $cur_state ][ $nt_id[ $sym ] ] = $to;
				}
				break;
			case 'reduction':
				if ( 'true' !== $reader->getAttribute( 'enabled' ) ) {
					break;   // Conflict loser discarded by Bison's precedence resolution.
				}
				$sym  = $reader->getAttribute( 'symbol' );
				$rule = $reader->getAttribute( 'rule' );
				$code = 'accept' === $rule ? 'A' : - (int) $rule;
				if ( '$default' === $sym ) {
					$adef[ $cur_state ] = $code;
				} else {
					$action[ $cur_state ][ $term_id[ $sym ] ] = $code;
				}
				break;
		}
	} elseif ( XMLReader::END_ELEMENT === $reader->nodeType && 'rhs' === $reader->name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XMLReader API property.
		$in_rhs = false;
	}
}
$reader->close();

fwrite(
	STDERR,
	sprintf(
		"Parsed: %d states, %d rules, %d terminals, %d nonterminals.\n",
		$ns,
		count( $rule_len ),
		count( $term_id ),
		count( $nt_id )
	)
);

// Accept is encoded as the state count (one past the last real state).
$resolve = function ( $code ) use ( $ns ) {
	return 'A' === $code ? $ns : $code;
};

/*
 * ACTION rows: per state, store only cells that differ from the state's
 * default reduce, then share identical rows between states.
 */
$state_default = array_fill( 0, $ns, 0 );
$state_row     = array_fill( 0, $ns, 0 );
$row_key       = array();
$rows          = array();
for ( $st = 0; $st < $ns; $st++ ) {
	$def                  = isset( $adef[ $st ] ) ? $resolve( $adef[ $st ] ) : 0;
	$state_default[ $st ] = $def;
	$cells                = array();
	foreach ( $action[ $st ] ?? array() as $token => $code ) {
		$rc = $resolve( $code );
		if ( $rc !== $def ) {
			$cells[ $token ] = $rc;
		}
	}
	ksort( $cells );
	$key = implode(
		';',
		array_map(
			function ( $t, $c ) {
				return "$t:$c";
			},
			array_keys( $cells ),
			$cells
		)
	);
	if ( ! isset( $row_key[ $key ] ) ) {
		$row_key[ $key ] = count( $rows );
		$rows[]          = $cells;
	}
	$state_row[ $st ] = $row_key[ $key ];
}

/*
 * Patch rows: a row may be stored as a difference against an earlier row whose
 * cell keys are a subset of its own (so the union "patch + base" reconstructs
 * it exactly, with no deletions). Greedily pick the base saving the most
 * cells; only large rows are worth considering as bases or patches.
 */
$row_base   = array();
$candidates = array();
$emitted    = array();   // Row id => the cells actually stored (full row or patch).
foreach ( $rows as $rid => $cells ) {
	$best_base = -1;
	$best_save = 8;   // A patch must save more than a handful of cells to pay off.
	if ( count( $cells ) > 8 ) {
		foreach ( $candidates as $bid ) {
			$base = $rows[ $bid ];
			if ( count( $base ) > count( $cells ) ) {
				continue;
			}
			$same = 0;
			foreach ( $base as $token => $code ) {
				if ( ! array_key_exists( $token, $cells ) ) {
					$same = -1;   // A deletion would be needed; not a valid base.
					break;
				}
				if ( $cells[ $token ] === $code ) {
					++$same;
				}
			}
			if ( $same > $best_save ) {
				$best_save = $same;
				$best_base = $bid;
			}
		}
	}
	if ( $best_base >= 0 ) {
		$row_base[ $rid ] = $best_base;
		$patch            = array();
		$base             = $rows[ $best_base ];
		foreach ( $cells as $token => $code ) {
			if ( ! array_key_exists( $token, $base ) || $base[ $token ] !== $code ) {
				$patch[ $token ] = $code;
			}
		}
		$emitted[ $rid ] = $patch;
	} else {
		$emitted[ $rid ] = $cells;
	}
	if ( count( $cells ) > 20 ) {
		$candidates[] = $rid;
	}
}

$stored_cells = 0;
$total_cells  = 0;
foreach ( $rows as $rid => $cells ) {
	$total_cells  += count( $cells );
	$stored_cells += count( $emitted[ $rid ] );
}

/*
 * GOTO: targets cluster by nonterminal, so store the most frequent target per
 * nonterminal as the default and per-state exceptions as a sparse nested map.
 */
$goto_default = array();
$freq_by_nt   = array();
for ( $st = 0; $st < $ns; $st++ ) {
	foreach ( $goto[ $st ] ?? array() as $nt => $target ) {
		$freq_by_nt[ $nt ][ $target ] = ( $freq_by_nt[ $nt ][ $target ] ?? 0 ) + 1;
	}
}
ksort( $freq_by_nt );
foreach ( $freq_by_nt as $nt => $freq ) {
	// Most frequent target wins; frequency ties keep the first-encountered
	// target (in state order) so the output is deterministic on any PHP version.
	$best_target = null;
	$best_count  = 0;
	foreach ( $freq as $target => $count ) {
		if ( $count > $best_count ) {
			$best_target = $target;
			$best_count  = $count;
		}
	}
	$goto_default[ $nt ] = $best_target;
}
$goto_exceptions = array();
for ( $st = 0; $st < $ns; $st++ ) {
	foreach ( $goto[ $st ] ?? array() as $nt => $target ) {
		if ( $target !== $goto_default[ $nt ] ) {
			$goto_exceptions[ $st ][ $nt ] = $target;
		}
	}
	if ( isset( $goto_exceptions[ $st ] ) ) {
		ksort( $goto_exceptions[ $st ] );
	}
}

// Per-production metadata: lhs symbol, rhs length, and a name (deduplicated).
$name_index = array();
$names      = array();
$rule_name  = array();
$rule_lhs_n = array();
$rule_len_n = array();
foreach ( $rule_len as $rule => $len ) {
	$nm = $rule_lhs[ $rule ] ?? '?';
	if ( ! isset( $name_index[ $nm ] ) ) {
		$name_index[ $nm ] = count( $names );
		$names[]           = $nm;
	}
	$rule_name[ $rule ]  = $name_index[ $nm ];
	$rule_lhs_n[ $rule ] = $nt_id[ $nm ] ?? 0;
	$rule_len_n[ $rule ] = $len;
}

/*
 * Emit minified plain PHP literals: sequential integer-keyed arrays drop their
 * keys, everything else is "key=>value", with no whitespace. This keeps the
 * artifact a plain, opcache-internable PHP array while staying compact.
 */
$emit       = function ( $value ) use ( &$emit ) {
	if ( ! is_array( $value ) ) {
		return (string) $value;
	}
	$sequential = array_keys( $value ) === range( 0, count( $value ) - 1 );
	$parts      = array();
	foreach ( $value as $k => $v ) {
		$parts[] = ( $sequential ? '' : $k . '=>' ) . $emit( $v );
	}
	return 'array(' . implode( ',', $parts ) . ')';
};
$emit_names = function ( array $names ) {
	$parts = array();
	foreach ( $names as $name ) {
		$parts[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name ) . "'";
	}
	return 'array(' . implode( ',', $parts ) . ')';
};

$sections = array(
	"'ns'=>" . $ns,
	"'start'=>0",
	"'dollar'=>" . ( $term_id['$end'] ?? 0 ),
	"'rows'=>" . $emit( $emitted ),
	"'row_base'=>" . $emit( $row_base ),
	"'state_row'=>" . $emit( $state_row ),
	"'state_default'=>" . $emit( $state_default ),
	"'goto_default'=>" . $emit( $goto_default ),
	"'goto_exceptions'=>" . $emit( $goto_exceptions ),
	"'rule_lhs'=>" . $emit( $rule_lhs_n ),
	"'rule_len'=>" . $emit( $rule_len_n ),
	"'rule_name'=>" . $emit( $rule_name ),
	"'names'=>" . $emit_names( $names ),
);

$php = "<?php\n"
	. "// THIS FILE IS GENERATED by tools/generate-parse-table.php. DO NOT EDIT.\n"
	. "// Source: MySQL Bison grammar (sql/sql_yacc.yy) at $mysql_tag.\n"
	. "// phpcs:disable\n"
	. 'return array(' . "\n" . implode( ",\n", $sections ) . "\n);\n";
file_put_contents( $output_path, $php );

fwrite(
	STDERR,
	sprintf(
		"rows=%d (patched=%d), cells stored=%d of %d | goto: %d defaults, %d exceptions | names=%d\n",
		count( $rows ),
		count( $row_base ),
		$stored_cells,
		$total_cells,
		count( $goto_default ),
		count( $goto_exceptions ),
		count( $names )
	)
);
echo round( filesize( $output_path ) / 1024 ) . " KB written to $output_path\n";
