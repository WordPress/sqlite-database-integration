<?php

/**
 * A parser grammar.
 *
 * This class represents a parser grammar that can be consumed by WP_Parser.
 * It loads a compressed grammar from a PHP array, inflates it to an internal
 * representation, and precomputes a lookup table for quick branch selection.
 *
 * @TODO: Add more details about the grammar implementation.
 */
class WP_Parser_Grammar {
	/**
	 * ID for a special grammar rule that represents an empty "ε" (epsilon) rule.
	 *
	 * An "ε" rule in a grammar is a rule that matches an empty input of 0 bytes.
	 * It can be used to represent optional grammar productions, and it is helpful
	 * for expanding 0-or-1, 0-or-more, and 1-or-more quantifiers into simple rules.
	 *
	 * @TODO Investigate whether we can prevent possible conflict with a token ID.
	 *       The MySQL grammar doesn't define a token with ID "0", but generally
	 *       token IDs are not guaranteed to always satisfy this condition.
	 */
	const EMPTY_RULE_ID = 0;

	/**
	 * @TODO: Review and document these properties and their visibility.
	 */
	public $rules;
	public $rule_names;
	public $rule_ids;
	public $fragment_ids;
	public $lookahead_is_match_possible = array();

	/**
	 * Per-branch FIRST sets: rule_id => branch_index => array<token_id, true>.
	 *
	 * An entry exists only when the branch's FIRST set is fully computable and
	 * does not contain the empty "ε" rule. A missing entry means the branch
	 * must always be attempted.
	 *
	 * @var array
	 */
	public $branch_first = array();

	public $lowest_non_terminal_id;
	public $highest_terminal_id;
	public $native_grammar;

	public function __construct( array $rules ) {
		$this->inflate( $rules );
	}

	public function get_rule_name( $rule_id ) {
		return $this->rule_names[ $rule_id ];
	}

	public function get_rule_id( $rule_name ) {
		return $this->rule_ids[ $rule_name ] ?? false;
	}

	/**
	 * Inflate the grammar to an internal representation optimized for parsing.
	 *
	 * The input grammar is a compressed PHP array to minimize the file size.
	 * Every rule and token in the compressed grammar is encoded as an integer.
	 */
	private function inflate( $grammar ) {
		$this->lowest_non_terminal_id = $grammar['rules_offset'];
		$this->highest_terminal_id    = $this->lowest_non_terminal_id - 1;

		foreach ( $grammar['rules_names'] as $rule_index => $rule_name ) {
			$this->rule_names[ $rule_index + $grammar['rules_offset'] ] = $rule_name;
			$this->rules[ $rule_index + $grammar['rules_offset'] ]      = array();

			/**
			 * Treat all intermediate rules as fragments to inline before returning
			 * the final parse tree to the API consumer.
			 *
			 * The original grammar was too difficult to parse with rules like:
			 *
			 *    query ::= EOF | ((simpleStatement | beginWork) ((SEMICOLON_SYMBOL EOF?) | EOF))
			 *
			 * We've factored rule fragments, such as `EOF?`, into separate rules, such as `%EOF_zero_or_one`.
			 * This is super useful for parsing, but it limits the API consumer's ability to
			 * reason about the parse tree.
			 *
			 * Fragments are intermediate rules that are not part of the original grammar.
			 * They are prefixed with a "%" to be distinguished from the original rules.
			 */
			if ( '%' === $rule_name[0] ) {
				$this->fragment_ids[ $rule_index + $grammar['rules_offset'] ] = true;
			}
		}
		$this->rule_ids = array_flip( $this->rule_names );

		$this->rules = array();
		foreach ( $grammar['grammar'] as $rule_index => $branches ) {
			$rule_id                 = $rule_index + $grammar['rules_offset'];
			$this->rules[ $rule_id ] = $branches;
		}

		/*
		 * Inline single-branch fragments before computing the lookup tables
		 * below, so that the tables reflect the compressed grammar.
		 */
		$this->inline_single_branch_fragments();

		/**
		 * Compute a rule => [token => true] lookup table for each rule
		 * that starts with a terminal OR with another rule that already
		 * has a lookahead mapping.
		 *
		 * This is similar to left-factoring the grammar, even if not quite
		 * the same.
		 *
		 * This enables us to quickly bail out from checking branches that
		 * cannot possibly match the current token. This increased the parser
		 * speed by a whopping 80%!
		 *
		 * @TODO: Explore these possible next steps:
		 *
		 * * Compute a rule => [token => branch[]] list lookup table and only
		 *   process the branches that have a chance of matching the current token.
		 * * Actually left-factor the grammar as much as possible. This, however,
		 *   could inflate the serialized grammar size.
		 */
		// 5 iterations seem to give us all the speed gains we can get from this.
		for ( $i = 0; $i < 5; $i++ ) {
			foreach ( $this->rules as $rule_id => $branches ) {
				if ( isset( $this->lookahead_is_match_possible[ $rule_id ] ) ) {
					continue;
				}
				$rule_lookup                                   = array();
				$first_symbol_can_be_expanded_to_all_terminals = true;
				foreach ( $branches as $branch ) {
					$terminals                   = false;
					$branch_starts_with_terminal = $branch[0] < $this->lowest_non_terminal_id;
					if ( $branch_starts_with_terminal ) {
						$terminals = array( $branch[0] );
					} elseif ( isset( $this->lookahead_is_match_possible[ $branch[0] ] ) ) {
						$terminals = array_keys( $this->lookahead_is_match_possible[ $branch[0] ] );
					}

					if ( false === $terminals ) {
						$first_symbol_can_be_expanded_to_all_terminals = false;
						break;
					}
					foreach ( $terminals as $terminal ) {
						$rule_lookup[ $terminal ] = true;
					}
				}
				if ( $first_symbol_can_be_expanded_to_all_terminals ) {
					$this->lookahead_is_match_possible[ $rule_id ] = $rule_lookup;
				}
			}
		}

		/**
		 * Compute per-branch FIRST sets.
		 *
		 * For each branch, compute the set of tokens its first symbol can start
		 * with. A branch with a known, epsilon-free FIRST set can be skipped
		 * when the current token is not in the set. Branches whose first symbol
		 * can derive the empty "ε" rule, or whose FIRST set is not computable,
		 * get no entry and are always attempted.
		 *
		 * While the rule-level lookahead table above answers "can this rule
		 * match the current token at all?", this table answers the finer
		 * question "which branches of this rule are worth trying?", letting
		 * the parser skip non-viable branches without recursing into them.
		 */
		// Branches starting with the same terminal share a single FIRST set
		// array to keep the memory footprint of the table low.
		$terminal_first_sets = array();
		foreach ( $this->rules as $rule_id => $branches ) {
			foreach ( $branches as $branch_index => $branch ) {
				$first_symbol = $branch[0];
				if ( $first_symbol < $this->lowest_non_terminal_id ) {
					if ( self::EMPTY_RULE_ID !== $first_symbol ) {
						if ( ! isset( $terminal_first_sets[ $first_symbol ] ) ) {
							$terminal_first_sets[ $first_symbol ] = array( $first_symbol => true );
						}
						$this->branch_first[ $rule_id ][ $branch_index ] = $terminal_first_sets[ $first_symbol ];
					}
				} elseif (
					isset( $this->lookahead_is_match_possible[ $first_symbol ] ) &&
					! isset( $this->lookahead_is_match_possible[ $first_symbol ][ self::EMPTY_RULE_ID ] )
				) {
					$this->branch_first[ $rule_id ][ $branch_index ] = $this->lookahead_is_match_possible[ $first_symbol ];
				}
			}
		}
	}

	/**
	 * Inline single-branch fragment rules into the branches that reference them.
	 *
	 * Fragment rules (names prefixed with "%") never appear in the final parse
	 * tree — the parser splices their children directly into the parent node.
	 * A reference to a fragment with a single branch is therefore pure call
	 * overhead: the parser recurses into the fragment only to match its one
	 * branch and splice the matched children back into the parent. Replacing
	 * the reference with the fragment's symbol sequence ahead of time produces
	 * a byte-identical parse tree while saving a recursive call per reference.
	 *
	 * Multi-branch fragments are left intact, as they encode alternation.
	 * Fragment references that are part of a recursion cycle through
	 * single-branch fragments are also left intact, which guarantees that
	 * the expansion terminates.
	 */
	private function inline_single_branch_fragments() {
		$inlinable = array();
		foreach ( $this->fragment_ids as $rule_id => $unused ) {
			if ( isset( $this->rules[ $rule_id ] ) && 1 === count( $this->rules[ $rule_id ] ) ) {
				$inlinable[ $rule_id ] = true;
			}
		}
		if ( ! $inlinable ) {
			return;
		}

		/*
		 * Fully expand every inlinable fragment first (fragments can reference
		 * other fragments), so that the splicing pass below is a single pass
		 * that doesn't depend on the order in which rules are processed.
		 */
		$expanded    = array();
		$in_progress = array();
		foreach ( $inlinable as $rule_id => $unused ) {
			$this->expand_fragment( $rule_id, $inlinable, $expanded, $in_progress );
		}

		foreach ( $this->rules as $rule_id => $branches ) {
			foreach ( $branches as $branch_index => $branch ) {
				$new_branch  = array();
				$has_changes = false;
				foreach ( $branch as $symbol ) {
					if ( isset( $expanded[ $symbol ] ) ) {
						foreach ( $expanded[ $symbol ] as $expanded_symbol ) {
							$new_branch[] = $expanded_symbol;
						}
						$has_changes = true;
					} else {
						$new_branch[] = $symbol;
					}
				}
				if ( $has_changes ) {
					$this->rules[ $rule_id ][ $branch_index ] = $new_branch;
				}
			}
		}
	}

	/**
	 * Compute the fully inlined symbol sequence of a single-branch fragment.
	 *
	 * @param int   $rule_id     ID of the fragment rule to expand.
	 * @param array $inlinable   Set of all single-branch fragment rule IDs.
	 * @param array $expanded    Memoized expansions, keyed by fragment rule ID.
	 * @param array $in_progress Fragments on the current expansion path.
	 *                           A reference to one of these is part of a
	 *                           recursion cycle and is kept as-is so that
	 *                           the expansion is guaranteed to terminate.
	 * @return array The expanded symbol sequence of the fragment's branch.
	 */
	private function expand_fragment( $rule_id, $inlinable, &$expanded, &$in_progress ) {
		if ( isset( $expanded[ $rule_id ] ) ) {
			return $expanded[ $rule_id ];
		}
		$in_progress[ $rule_id ] = true;
		$result                  = array();
		foreach ( $this->rules[ $rule_id ][0] as $symbol ) {
			if ( isset( $inlinable[ $symbol ] ) && ! isset( $in_progress[ $symbol ] ) ) {
				foreach ( $this->expand_fragment( $symbol, $inlinable, $expanded, $in_progress ) as $expanded_symbol ) {
					$result[] = $expanded_symbol;
				}
			} else {
				$result[] = $symbol;
			}
		}
		unset( $in_progress[ $rule_id ] );
		$expanded[ $rule_id ] = $result;
		return $result;
	}
}
