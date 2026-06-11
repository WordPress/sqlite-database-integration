<?php

/**
 * A context-free grammar represented by LALR(1) parse tables.
 *
 * This class loads a compacted parse table and expands it into a LALR(1) grammar
 * representation that is memory-efficient and optimized for the WP_Parser runtime.
 * The expanded parse tables are stored in public properties for efficient access.
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
class WP_Parser_Grammar {
	/**
	 * The total number of states.
	 *
	 * Doubles as the action code that means "accept" (one past the last state).
	 *
	 * @var int
	 */
	public $state_count;

	/**
	 * The state in which parsing begins.
	 *
	 * This is the initial entry on the state stack, before any token is read.
	 *
	 * @var int
	 */
	public $start_state;

	/**
	 * The end-of-input token number.
	 *
	 * Used as the final lookahead once the input tokens are exhausted.
	 *
	 * @var int
	 */
	public $end_token;

	/**
	 * ACTION TABLE: Maps "(STATE, TOKEN) => ACTION".
	 *
	 * Given a state and a lookahead token, what action should the parser take?
	 *
	 * This is a sparse array, encoding only the cells that differ from per-state
	 * default actions. Any token not listed falls through to action defaults.
	 *
	 * @var array<int, array<int, int>> A nested "STATE => [ TOKEN => ACTION ]" map.
	 */
	public $action_table;

	/**
	 * ACTION DEFAULTS: Maps "STATE => ACTION" for records not in action table.
	 *
	 * To avoid listing every lookahead token for each state in the action table,
	 * the default action for each state is recorded here.
	 *
	 * This is a dense array that maps each state to its default action.
	 *
	 * @var array<int, int> A "STATE => ACTION" map.
	 */
	public $action_defaults;

	/**
	 * GOTO TABLE: Maps "(NONTERMINAL, STATE) => target STATE".
	 *
	 * After reducing to a nonterminal, what next state should the parser enter?
	 *
	 * This is a sparse array, encoding only the cells that differ from per-nonterminal
	 * default targets. Any state not listed falls through to goto defaults.
	 *
	 * @var array<int, array<int, int>> A nested "NONTERMINAL => [ STATE => target STATE ]" map.
	 */
	public $goto_table;

	/**
	 * GOTO DEFAULTS: Maps "NONTERMINAL => target STATE" for records not in goto table.
	 *
	 * To avoid listing every state for each nonterminal in the goto table, the
	 * default target state for each nonterminal is recorded here.
	 *
	 * This is a dense array that maps each nonterminal to its default target state.
	 *
	 * @var array<int, int> A "NONTERMINAL => target STATE" map.
	 */
	public $goto_defaults;

	/**
	 * PRODUCTION LHS: Maps "PRODUCTION => left-hand-side NONTERMINAL".
	 *
	 * Which nonterminal (left-hand side) does a production reduce to?
	 *
	 * This is a dense array. The lhs becomes the reduced node's rule id and
	 * selects its GOTO column.
	 *
	 * @var array<int, int> A "PRODUCTION => NONTERMINAL" map.
	 */
	public $production_lhs;

	/**
	 * PRODUCTION LENGTHS: Maps "PRODUCTION => right-hand-side SYMBOL COUNT".
	 *
	 * How many symbols (production's right-hand side) are popped off the stack
	 * when a production is reduced?
	 *
	 * This is a dense array. The length is the number of symbols popped off the
	 * stack to form the node.
	 *
	 * @var array<int, int> A "PRODUCTION => SYMBOL COUNT" map.
	 */
	public $production_lengths;

	/**
	 * RULE NAMES: Maps "NONTERMINAL => RULE NAME".
	 *
	 * What is the readable name of the rule that a nonterminal heads?
	 *
	 * This is a dense array, indexed by the contiguous lhs ids. When a production
	 * reduces, its node is labeled with the name of its lhs nonterminal.
	 *
	 * @var array<int, string> A "NONTERMINAL => RULE NAME" map.
	 */
	public $rule_names;

	/**
	 * Constructor.
	 *
	 * @param array $table Compacted parse table representing a context-free LALR(1) grammar.
	 */
	public function __construct( array $table ) {
		// Initialize and expand the grammar from the compacted parse table.
		$this->state_count        = $table['state_count'];
		$this->start_state        = $table['start_state'];
		$this->end_token          = $table['end_token'];
		$this->action_defaults    = $table['action_defaults'];
		$this->goto_table         = $table['goto_table'];
		$this->goto_defaults      = $table['goto_defaults'];
		$this->production_lhs     = $table['production_lhs'];
		$this->production_lengths = $table['production_lengths'];
		$this->rule_names         = $table['rule_names'];

		// The compacted parse table stores action rows compactly to save space.
		// Expand them back into full "STATE => [ TOKEN => ACTION ]" rows.

		// 1. Restore the shifts stored as plain token lists.
		$rows          = $table['action_rows'];
		$shift_targets = $table['action_shift_targets'];
		foreach ( $table['action_row_shift_tokens'] as $row_id => $tokens ) {
			foreach ( $tokens as $token ) {
				$rows[ $row_id ][ $token ] = $shift_targets[ $token ];
			}
		}

		// 2. Merge each diffed row onto its base. The array is sorted, and a base
		//    comes before its dependents, so it will be expanded before them.
		foreach ( $table['action_row_bases'] as $row_id => $base_row_id ) {
			$rows[ $row_id ] += $rows[ $base_row_id ];
		}

		// 3. Point each state at its action row. States often share one.
		$action_table = array();
		foreach ( $table['action_table'] as $state => $row_id ) {
			$action_table[ $state ] = $rows[ $row_id ];
		}
		$this->action_table = $action_table;
	}
}
