<?php
/**
 * Generate the LALR(1) parse table from Bison's --xml automaton dump.
 *
 * Reads the automaton produced by `bison --xml` for MySQL's sql_yacc.yy and
 * emits the ACTION/GOTO tables as plain PHP arrays that WP_Parser
 * executes. The MySQL grammar is unambiguous for LALR(1) (Bison resolves every
 * shift/reduce conflict by precedence and reports zero reduce/reduce
 * conflicts), so each (state, token) cell holds a single action.
 *
 * The table is kept small with four structural devices, all in plain PHP:
 *
 *   1. Per-state default reduce ('action_defaults'): most states reduce by the
 *      same production for nearly every lookahead; only differing cells are
 *      stored, as sparse token => action rows.
 *   2. Row sharing ('action_table'): states with identical cell sets point at
 *      a single shared row.
 *   3. Patch rows ('action_row_bases'): the keyword-heavy rows are hundreds of cells
 *      each but nearly identical to one another, so a row may be stored as a
 *      small patch over an earlier base row; the runtime applies patches with
 *      an array union at construction time.
 *   4. Modal shift targets ('action_shift_targets' + 'action_row_shift_tokens'): most shifts
 *      on a given terminal go to the same successor state, so such cells are
 *      stored as bare token lists and restored from a per-terminal target
 *      table at construction time.
 *
 *   GOTO targets cluster by nonterminal instead, so they are stored as a
 *   per-nonterminal default ('goto_defaults') plus the sparse non-default
 *   targets ('goto_table'), keyed by nonterminal. The defaults and the rule
 *   names are both dense over the (contiguous) nonterminal ids, so they are
 *   keyed by nonterminal id directly — written compactly as
 *   [<base>=>first,rest,...] using PHP's literal key auto-increment — and the
 *   runtime indexes them with no reindexing. A rule's name is rule_names[lhs].
 *
 * Action codes (int): 0 = syntax error; a positive code below the state count
 * = shift to that state; the state count = accept; a negative code = reduce
 * by the production with that number negated.
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

$terminal_id    = array();   // Terminal name => token-number (the lexer's token ids).
$nonterminal_id = array();   // Nonterminal name => symbol-number.
$rule_lhs       = array();   // Rule number => lhs name.
$rule_len       = array();   // Rule number => rhs length.
$action         = array();   // State => [ token-number => code ] (>0 shift, <0 reduce, 'A' accept).
$goto           = array();   // State => [ symbol-number => target state ].
$action_default = array();   // State => default reduce code.
$state_count    = 0;         // Number of states.

// Resolve a grammar symbol to its id, failing loudly if the automaton ever
// references one the grammar tables don't define (a malformed or unexpected
// Bison dump) instead of silently writing a wrong cell.
$require_terminal    = function ( $sym ) use ( &$terminal_id ) {
	if ( ! isset( $terminal_id[ $sym ] ) ) {
		fwrite( STDERR, "error: unknown terminal '$sym' in the automaton.\n" );
		exit( 1 );
	}
	return $terminal_id[ $sym ];
};
$require_nonterminal = function ( $sym ) use ( &$nonterminal_id ) {
	if ( ! isset( $nonterminal_id[ $sym ] ) ) {
		fwrite( STDERR, "error: unknown nonterminal '$sym' in the automaton.\n" );
		exit( 1 );
	}
	return $nonterminal_id[ $sym ];
};

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
				$terminal_id[ $reader->getAttribute( 'name' ) ] = (int) $reader->getAttribute( 'token-number' );
				break;
			case 'nonterminal':
				$nonterminal_id[ $reader->getAttribute( 'name' ) ] = (int) $reader->getAttribute( 'symbol-number' );
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
				if ( $cur_state + 1 > $state_count ) {
					$state_count = $cur_state + 1;
				}
				break;
			case 'transition':
				$sym    = $reader->getAttribute( 'symbol' );
				$target = (int) $reader->getAttribute( 'state' );
				if ( 'shift' === $reader->getAttribute( 'type' ) ) {
					$action[ $cur_state ][ $require_terminal( $sym ) ] = $target;   // Shift target state (> 0).
				} else {
					$goto[ $cur_state ][ $require_nonterminal( $sym ) ] = $target;
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
					$action_default[ $cur_state ] = $code;
				} else {
					$action[ $cur_state ][ $require_terminal( $sym ) ] = $code;
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
		$state_count,
		count( $rule_len ),
		count( $terminal_id ),
		count( $nonterminal_id )
	)
);

// Accept is encoded as the state count (one past the last real state).
$resolve = function ( $code ) use ( $state_count ) {
	return 'A' === $code ? $state_count : $code;
};

/*
 * ACTION rows: per state, store only cells that differ from the state's
 * default reduce, then share identical rows between states.
 */
$state_default = array_fill( 0, $state_count, 0 );
$state_row     = array_fill( 0, $state_count, 0 );
$row_key       = array();
$rows          = array();
for ( $state = 0; $state < $state_count; $state++ ) {
	$default_code            = isset( $action_default[ $state ] ) ? $resolve( $action_default[ $state ] ) : 0;
	$state_default[ $state ] = $default_code;
	$cells                   = array();
	foreach ( $action[ $state ] ?? array() as $token => $code ) {
		$resolved = $resolve( $code );
		if ( $resolved !== $default_code ) {
			$cells[ $token ] = $resolved;
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
	$state_row[ $state ] = $row_key[ $key ];
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
foreach ( $rows as $row_id => $cells ) {
	$best_base = -1;
	$best_save = 8;   // A patch must save more than a handful of cells to pay off.
	if ( count( $cells ) > 8 ) {
		foreach ( $candidates as $base_id ) {
			$base = $rows[ $base_id ];
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
				$best_base = $base_id;
			}
		}
	}
	if ( $best_base >= 0 ) {
		// The runtime merges each patch onto its base in a single ascending pass,
		// so a base must come before its dependent row and be expanded first. Only
		// earlier rows ever enter $candidates, which guarantees this; assert it so a
		// future change to the base search can't silently break reconstruction.
		if ( $best_base >= $row_id ) {
			fwrite( STDERR, "error: patch base $best_base is not before row $row_id; bases must be expanded before their dependents.\n" );
			exit( 1 );
		}
		$row_base[ $row_id ] = $best_base;
		$patch               = array();
		$base                = $rows[ $best_base ];
		foreach ( $cells as $token => $code ) {
			if ( ! array_key_exists( $token, $base ) || $base[ $token ] !== $code ) {
				$patch[ $token ] = $code;
			}
		}
		$emitted[ $row_id ] = $patch;
	} else {
		$emitted[ $row_id ] = $cells;
	}
	if ( count( $cells ) > 20 ) {
		$candidates[] = $row_id;
	}
}

$stored_cells = 0;
$total_cells  = 0;
foreach ( $rows as $row_id => $cells ) {
	$total_cells  += count( $cells );
	$stored_cells += count( $emitted[ $row_id ] );
}

/*
 * Modal shift targets: for each terminal, find the most common shift target
 * among the stored cells. Cells that hit it are emitted as bare token lists
 * ('action_row_shift_tokens') instead of token => target pairs; the runtime restores
 * them from the per-terminal table ('action_shift_targets') at construction time.
 */
$shift_freq = array();
foreach ( $emitted as $cells ) {
	foreach ( $cells as $token => $code ) {
		if ( $code > 0 && $code < $state_count ) {
			$shift_freq[ $token ][ $code ] = ( $shift_freq[ $token ][ $code ] ?? 0 ) + 1;
		}
	}
}
ksort( $shift_freq );
$shift_target = array();
foreach ( $shift_freq as $token => $freq ) {
	// Most frequent target wins; ties keep the first-encountered target so the
	// output is deterministic on any PHP version.
	$best_target = null;
	$best_count  = 0;
	foreach ( $freq as $target => $count ) {
		if ( $count > $best_count ) {
			$best_target = $target;
			$best_count  = $count;
		}
	}
	$shift_target[ $token ] = $best_target;
}
$row_shifts  = array();
$modal_cells = 0;
foreach ( $emitted as $row_id => $cells ) {
	foreach ( $cells as $token => $code ) {
		if ( $code > 0 && $code < $state_count && $shift_target[ $token ] === $code ) {
			$row_shifts[ $row_id ][] = $token;
			unset( $emitted[ $row_id ][ $token ] );
			++$modal_cells;
		}
	}
}

/*
 * GOTO: targets cluster by nonterminal, so store the most frequent target per
 * nonterminal as the default and per-state exceptions as a sparse nested map.
 */
$goto_default        = array();
$freq_by_nonterminal = array();
for ( $state = 0; $state < $state_count; $state++ ) {
	foreach ( $goto[ $state ] ?? array() as $nonterminal => $target ) {
		$freq_by_nonterminal[ $nonterminal ][ $target ] = ( $freq_by_nonterminal[ $nonterminal ][ $target ] ?? 0 ) + 1;
	}
}
ksort( $freq_by_nonterminal );
foreach ( $freq_by_nonterminal as $nonterminal => $freq ) {
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
	$goto_default[ $nonterminal ] = $best_target;
}
// Keyed by nonterminal (434 wrappers) rather than by state (1,491): the same
// cells in far fewer enclosing arrays.
$goto_table = array();
for ( $state = 0; $state < $state_count; $state++ ) {
	foreach ( $goto[ $state ] ?? array() as $nonterminal => $target ) {
		if ( $target !== $goto_default[ $nonterminal ] ) {
			$goto_table[ $nonterminal ][ $state ] = $target;
		}
	}
}
ksort( $goto_table );

/*
 * Per-production metadata: lhs symbol and rhs length. Rule names are stored per
 * nonterminal, not per production: Bison numbers the nonterminals contiguously,
 * so the names are keyed by nonterminal id and the reduce step looks up a rule's
 * name by its lhs.
 */
$production_lhs     = array();
$production_lengths = array();
$lhs_name           = array();   // Nonterminal id => name.
foreach ( $rule_len as $rule => $len ) {
	$name                        = $rule_lhs[ $rule ] ?? '?';
	$lhs                         = $require_nonterminal( $name );
	$production_lhs[ $rule ]     = $lhs;
	$production_lengths[ $rule ] = $len;
	$lhs_name[ $lhs ]            = $name;
}
ksort( $lhs_name );
$lhs_base = (int) array_keys( $lhs_name )[0];
$last_lhs = (int) array_keys( $lhs_name )[ count( $lhs_name ) - 1 ];
if ( count( $lhs_name ) !== $last_lhs - $lhs_base + 1 ) {
	fwrite( STDERR, "error: nonterminal lhs ids are not contiguous ($lhs_base..$last_lhs vs " . count( $lhs_name ) . " names); the names list cannot be indexed by lhs.\n" );
	exit( 1 );
}
$names = array_values( $lhs_name );

/*
 * GOTO defaults cover every nonterminal except the start symbol (lhs_base),
 * which is never a GOTO target, so they form the contiguous range lhs_base + 1
 * .. last nonterminal. They are emitted keyed by nonterminal id (writing only
 * the first key, see emit_from), so the runtime indexes them directly. Fail
 * loudly if a future grammar breaks the contiguity.
 */
ksort( $goto_default );
$goto_base = $lhs_base + 1;
if ( array_keys( $goto_default ) !== range( $goto_base, $goto_base + count( $goto_default ) - 1 ) ) {
	fwrite( STDERR, "error: goto defaults are not the contiguous range $goto_base..; cannot store them positionally.\n" );
	exit( 1 );
}
$goto_default_list = array_values( $goto_default );

/*
 * Emit minified plain PHP literals: sequential integer-keyed arrays drop their
 * keys, everything else is "key=>value", with no whitespace, using the short
 * array syntax. This keeps the artifact a plain, opcache-internable PHP array
 * while staying compact.
 */
$emit        = function ( $value ) use ( &$emit ) {
	if ( ! is_array( $value ) ) {
		return (string) $value;
	}
	$sequential = array_keys( $value ) === range( 0, count( $value ) - 1 );
	$parts      = array();
	foreach ( $value as $k => $v ) {
		$parts[] = ( $sequential ? '' : $k . '=>' ) . $emit( $v );
	}
	return '[' . implode( ',', $parts ) . ']';
};
$escape_name = function ( $name ) {
	return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name ) . "'";
};
// Emit a contiguous integer-keyed array compactly: write only the first key and
// let PHP's literal key auto-increment fill in the rest, so a list keyed from
// $base becomes [<base>=>v0,v1,v2,...]. The runtime then gets the keyed array
// directly, with no reindexing.
$emit_from = function ( array $values, $base, callable $emit_value ) {
	$parts = array();
	$first = true;
	foreach ( array_values( $values ) as $v ) {
		$parts[] = ( $first ? $base . '=>' : '' ) . $emit_value( $v );
		$first   = false;
	}
	return '[' . implode( ',', $parts ) . ']';
};

// Group the fields logically and label each group, so the generated artifact
// stays readable: dimensions, the ACTION table, the GOTO table, per-rule data.
$groups = array(
	'Dimensions'   => array(
		"'state_count'=>" . $state_count,
		"'start_state'=>0",
		"'end_token'=>" . ( $terminal_id['$end'] ?? 0 ),
	),
	'ACTION table' => array(
		"'action_rows'=>" . $emit( $emitted ),
		"'action_shift_targets'=>" . $emit( $shift_target ),
		"'action_row_shift_tokens'=>" . $emit( $row_shifts ),
		"'action_row_bases'=>" . $emit( $row_base ),
		"'action_table'=>" . $emit( $state_row ),
		"'action_defaults'=>" . $emit( $state_default ),
	),
	'GOTO table'   => array(
		"'goto_table'=>" . $emit( $goto_table ),
		"'goto_defaults'=>" . $emit_from( $goto_default_list, $goto_base, $emit ),
	),
	'Per-rule'     => array(
		"'production_lhs'=>" . $emit( $production_lhs ),
		"'production_lengths'=>" . $emit( $production_lengths ),
		"'rule_names'=>" . $emit_from( $names, $lhs_base, $escape_name ),
	),
);

$body = array();
foreach ( $groups as $label => $entries ) {
	$body[] = "// $label.";
	foreach ( $entries as $entry ) {
		$body[] = $entry . ',';
	}
}

$php = "<?php\n"
	. "// THIS FILE IS GENERATED by tools/generate-parse-table.php. DO NOT EDIT.\n"
	. "// Source: MySQL Bison grammar (sql/sql_yacc.yy) at $mysql_tag.\n"
	. "// phpcs:disable\n"
	. 'return [' . "\n" . implode( "\n", $body ) . "\n];\n";
file_put_contents( $output_path, $php );

fwrite(
	STDERR,
	sprintf(
		"rows=%d (patched=%d), cells stored=%d of %d (%d as modal shifts) | goto: %d table groups, %d defaults | names=%d\n",
		count( $rows ),
		count( $row_base ),
		$stored_cells,
		$total_cells,
		$modal_cells,
		count( $goto_table ),
		count( $goto_default ),
		count( $names )
	)
);
echo round( filesize( $output_path ) / 1024 ) . " KB written to $output_path\n";
