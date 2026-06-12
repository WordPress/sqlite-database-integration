<?php

/**
 * Table-driven LALR(1) parser for MySQL.
 *
 * A bottom-up shift-reduce parser that consumes the ACTION/GOTO tables
 * generated from MySQL's official Bison grammar by
 * tools/generate-parse-table.php, and builds a WP_Parser_Node AST.
 *
 * The MySQL grammar is unambiguous for LALR(1): Bison resolves its shift/reduce
 * conflicts by precedence (and the default shift preference declared by
 * %expect) and reports zero reduce/reduce conflicts. Each (state, token) cell
 * therefore holds a single action, so the parser is a plain deterministic loop
 * with no conflict handling, backtracking, or GLR forking.
 *
 * The generated table stores ACTION cells as sparse per-state rows over a
 * per-state default reduce, with identical rows shared between states and
 * near-identical rows patch-encoded against a base row (see the generator).
 * The constructor applies the patches and points each state at its shared row,
 * so the parse loop is two plain array lookups per step.
 *
 * AST contract: each node carries the grammar rule name it was reduced by. By
 * default every rule materialises a node, including the grammar's deep
 * single-child wrapper chains (expr -> bool_pri -> predicate -> bit_expr ->
 * ...). Passing $inline_unit_productions = true to the constructor inlines
 * unit productions whose only child is itself a node — over half of all
 * reductions — which roughly halves node allocations for a further 20-30%
 * of throughput, at the cost of the wrapper rule names being absent from the
 * tree; consumers must then match only the rule names of meaningful
 * (multi-child or token-bearing) nodes.
 *
 * Action codes (int): 0 = syntax error; 1..ns-1 = shift to that state;
 * ns = accept; < 0 = reduce by production -code.
 */
class WP_MySQL_Parser {
	/**
	 * The path to the generated parse table file.
	 *
	 * The table is data, not a class, so it is not covered by autoloading.
	 * Consumers can use this path to load it without depending on the
	 * package file layout: new WP_MySQL_Parser( require WP_MySQL_Parser::PARSE_TABLE_PATH ).
	 */
	const PARSE_TABLE_PATH = __DIR__ . '/grammar/parse-table.php';

	// ACTION: per-state sparse row (token id => action code) + default code.
	private $action;            // State => row (shared between states).
	private $action_default;    // State => default reduce code.

	// GOTO: per-nonterminal default target + sparse exceptions.
	private $goto_exceptions;   // Nonterminal id => ( state => target state ).
	private $goto_default;      // Nonterminal id => default target state.

	// Productions.
	private $rule_lhs;          // Production id => lhs nonterminal id.
	private $rule_len;          // Production id => rhs length.
	private $rule_name;         // Production id => rule name.

	private $ns;                // Number of states (also the accept code).
	private $start;             // Start state.
	private $dollar;            // End-of-input token number ($end).

	/** Whether unit productions over a node are inlined instead of wrapped. */
	private $inline_unit_productions;

	public function __construct( array $table, bool $inline_unit_productions = false ) {
		$this->inline_unit_productions = $inline_unit_productions;

		$this->ns     = $table['ns'];
		$this->start  = $table['start'];
		$this->dollar = $table['dollar'];

		// Materialise the rows: first restore the shift cells stored as bare
		// token lists from the per-terminal modal target table, then apply the
		// patch encoding — a patch holds only the cells that differ from its
		// base row, so the union "patch + base" reconstructs the full row
		// (bases always precede their patches).
		$rows         = $table['rows'];
		$shift_target = $table['shift_target'];
		foreach ( $table['row_shifts'] as $rid => $tokens ) {
			foreach ( $tokens as $token ) {
				$rows[ $rid ][ $token ] = $shift_target[ $token ];
			}
		}
		foreach ( $table['row_base'] as $rid => $base ) {
			$rows[ $rid ] += $rows[ $base ];
		}

		// Point each state at its shared row (copy-on-write references).
		$this->action = array();
		foreach ( $table['state_row'] as $state => $rid ) {
			$this->action[ $state ] = $rows[ $rid ];
		}
		$this->action_default = $table['state_default'];

		$this->goto_exceptions = $table['goto_exceptions'];
		$this->goto_default    = $table['goto_default'];

		$this->rule_lhs = $table['rule_lhs'];
		$this->rule_len = $table['rule_len'];

		// Resolve each production's rule name once, off the reduce hot path.
		// The names list is ordered by the grammar's contiguous nonterminal ids.
		$names           = $table['names'];
		$lhs_base        = $table['lhs_base'];
		$this->rule_name = array();
		foreach ( $table['rule_lhs'] as $production => $lhs ) {
			$this->rule_name[ $production ] = $names[ $lhs - $lhs_base ];
		}
	}

	/**
	 * Parse a token stream into an AST.
	 *
	 * The table data is hoisted into locals because the shift-reduce loop reads
	 * it on every step; local reads beat repeated $this-> property fetches.
	 *
	 * @param WP_Parser_Token[] $tokens Tokens from the lexer, terminated by $end.
	 * @return WP_Parser_Node|null The AST root, or null on a syntax error.
	 */
	public function parse( array $tokens ) {
		$action = $this->action;
		$a_def  = $this->action_default;
		$gx     = $this->goto_exceptions;
		$g_def  = $this->goto_default;
		$plen   = $this->rule_len;
		$plhs   = $this->rule_lhs;
		$p_name = $this->rule_name;
		$ns     = $this->ns;
		$dollar = $this->dollar;
		$inline = $this->inline_unit_productions;

		// Hoist token ids into a flat int array: the loop reads the lookahead far
		// more often than it shifts, and an array read beats an object property
		// fetch. The token objects themselves become the AST leaves on shift.
		$ids = array();
		foreach ( $tokens as $token ) {
			$ids[] = $token->id;
		}
		$n = count( $ids );

		// Two parallel stacks: $sstack holds states, $nstack holds the symbols
		// (tokens and nodes). The symbol top sits one below the state top, so
		// nstack[k] corresponds to sstack[k + 1].
		$sstack = array( $this->start );
		$nstack = array();
		$sp     = 0;   // Index of the state-stack top.
		$i      = 0;   // Lookahead position.

		while ( true ) {
			$state = $sstack[ $sp ];
			$la    = $i < $n ? $ids[ $i ] : $dollar;
			$code  = $action[ $state ][ $la ] ?? $a_def[ $state ];

			if ( $code > 0 ) {
				if ( $code < $ns ) {
					// Shift: push the token as a symbol and enter the next state.
					// A stream missing its $end terminator (the lexer's partial,
					// invalid-input output) can select a shift on the virtual
					// end-of-input lookahead; there is no token to shift then,
					// so it is a syntax error.
					if ( $i >= $n ) {
						return null;
					}
					$nstack[ $sp ]   = $tokens[ $i ];
					$sstack[ ++$sp ] = $code;
					++$i;
					continue;
				}
				// Accept. The start rule is "$accept: start_entry $end" and the
				// $end token was just shifted, so the AST root sits one below the
				// symbol-stack top.
				return $sp >= 2 ? $nstack[ $sp - 2 ] : null;
			}

			if ( 0 === $code ) {
				return null;   // Syntax error.
			}

			// Reduce by production -code: the handle is the top $len symbols at
			// nstack[$base .. $sp-1]. Build the node in place by moving the stack
			// pointer instead of splicing. In inlining mode, a unit production
			// over a node passes the child through unchanged instead of wrapping
			// it (see the AST contract in the class docblock).
			$p    = -$code;
			$lhs  = $plhs[ $p ];
			$base = $sp - $plen[ $p ];
			if ( ! $inline || $base + 1 !== $sp || ! $nstack[ $base ] instanceof WP_Parser_Node ) {
				$kids = array();
				for ( $j = $base; $j < $sp; $j++ ) {
					$kids[] = $nstack[ $j ];
					if ( $j > $base ) {
						$nstack[ $j ] = null;
					}
				}
				$nstack[ $base ] = new WP_Parser_Node( $lhs, $p_name[ $p ], $kids );
			}

			// GOTO on $lhs from the state now exposed under the handle.
			$sstack[ $base + 1 ] = $gx[ $lhs ][ $sstack[ $base ] ] ?? $g_def[ $lhs ];
			$sp                  = $base + 1;
		}
	}
}
