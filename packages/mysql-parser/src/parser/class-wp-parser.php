<?php

/**
 * A LALR(1) parser.
 *
 * A fast universal LALR(1) parser for context-free grammars written in pure PHP.
 * The parser reads input tokens and builds an abstract syntax tree (AST) according
 * to a specific grammar that is represented by a WP_Parser_Grammar instance.
 *
 * The parser is a bottom-up shift-reduce automaton driven by ACTION and GOTO
 * tables of a specific grammar. Each state-token pair maps to a single action.
 * Parsing is therefore deterministic, with no backtracking or conflicts.
 *
 * The grammar representation is closely based on GNU Bison parse table format.
 * See: https://www.gnu.org/software/bison/manual/bison.html
 *
 * The following are the key components of the grammar representation:
 *
 *   STATE:       A position in the parsing state machine.
 *   TOKEN:       A symbol that represents a concrete terminal in the grammar.
 *   NONTERMINAL: A symbol that represents an abstract placeholder in the grammar.
 *   RULE:        A definition of how a nonterminal can be rewritten into symbols.
 *   PRODUCTION:  A specific instance of a rule representing a concrete rewrite.
 *   ACTION:      What to do for a (STATE, TOKEN) pair. Encoded as an integer N:
 *     N < 0               => reduce by production -N
 *     N = 0               => syntax error
 *     0 < N < state_count => shift the token and enter state N
 *     N = state_count     => accept
 */
class WP_Parser {
	/**
	 * A context-free LALR(1) grammar instance.
	 *
	 * @var WP_Parser_Grammar
	 */
	private $grammar;

	/**
	 * Constructor.
	 *
	 * @param WP_Parser_Grammar $grammar A context-free LALR(1) grammar instance.
	 */
	public function __construct( WP_Parser_Grammar $grammar ) {
		$this->grammar = $grammar;
	}

	/**
	 * Parse a token stream into an abstract syntax tree (AST).
	 *
	 * @param  WP_Parser_Token[] $tokens A list of tokens from the lexer.
	 * @return WP_Parser_Node|null       The generated AST on success, or null on a syntax error.
	 */
	public function parse( array $tokens ) {
		// Optimization: Copy properties into locals for faster access in the loop.
		$grammar            = $this->grammar;
		$action_table       = $grammar->action_table;
		$action_defaults    = $grammar->action_defaults;
		$goto_table         = $grammar->goto_table;
		$goto_defaults      = $grammar->goto_defaults;
		$production_lengths = $grammar->production_lengths;
		$production_lhs     = $grammar->production_lhs;
		$rule_names         = $grammar->rule_names;
		$state_count        = $grammar->state_count;
		$end_token          = $grammar->end_token;

		// Optimization: Precompute token count and IDs. The loop reads them often.
		$token_ids = array();
		foreach ( $tokens as $token ) {
			$token_ids[] = $token->id;
		}
		$token_count = count( $token_ids );

		/*
		 * The parser configuration:
		 *   $state_stack:  A stack tracking the current parser state path.
		 *   $symbol_stack: A stack storing consumed tokens and constructed nodes.
		 *   $stack_top:    Tracks position of the top in both stacks:
		 *                    - The state stack is at $stack_top.
		 *                    - The symbol stack is at $stack_top - 1.
		 *   $input_pos:    Lookahead position in the input token stream.
		 */
		$state_stack  = array( $grammar->start_state );
		$symbol_stack = array();
		$stack_top    = 0;
		$input_pos    = 0;

		while ( true ) {
			$state       = $state_stack[ $stack_top ];
			$lookahead   = $input_pos < $token_count ? $token_ids[ $input_pos ] : $end_token;
			$action_code = $action_table[ $state ][ $lookahead ] ?? $action_defaults[ $state ];

			if ( $action_code < 0 ) {
				// REDUCE: Pop symbols off the stack and replace them with a node.
				$production = -$action_code;
				$lhs        = $production_lhs[ $production ];
				$base       = $stack_top - $production_lengths[ $production ];

				$children = array();
				for ( $j = $base; $j < $stack_top; $j++ ) {
					if ( null !== $symbol_stack[ $j ] ) {
						$children[] = $symbol_stack[ $j ];
					}
				}

				// A production with no children produces no node (null in the stack).
				$symbol_stack[ $base ] = $children
					? new WP_Parser_Node( $lhs, $rule_names[ $lhs ], $children )
					: null;

				// GOTO: Enter the target state for this rule.
				$stack_top                 = $base + 1;
				$state_stack[ $stack_top ] = $goto_table[ $lhs ][ $state_stack[ $base ] ] ?? $goto_defaults[ $lhs ];
			} elseif ( 0 === $action_code ) {
				// SYNTAX ERROR: The parser encountered an invalid token.
				return null;
			} elseif ( $action_code < $state_count ) {
				// SHIFT: Push the input token and enter the next state.
				if ( $input_pos >= $token_count ) {
					// SYNTAX ERROR: No token left to shift.
					return null;
				}
				$symbol_stack[ $stack_top ] = $tokens[ $input_pos ];
				$stack_top                 += 1;
				$state_stack[ $stack_top ]  = $action_code;
				$input_pos                 += 1;
			} else {
				// ACCEPT: The AST root is just below the end token.
				return $stack_top >= 2 ? $symbol_stack[ $stack_top - 2 ] : null;
			}
		}
	}
}
