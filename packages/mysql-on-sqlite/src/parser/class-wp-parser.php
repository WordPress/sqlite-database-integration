<?php

/**
 * A recursive descent parser.
 *
 * This is a dynamic recursive descent parser that can parse LL grammars.
 *
 * To keep parsing fast, the hot recursive routine applies these techniques:
 *
 *   1. Lazy node creation: children are accumulated in a local array, and
 *      a WP_Parser_Node is allocated only when a branch fully matches.
 *      Discarded branches therefore allocate no nodes at all. Fragment
 *      rules return plain children arrays that are spliced into the parent,
 *      so no intermediate fragment nodes are ever created.
 *   2. Per-branch FIRST sets: branches that cannot possibly match the
 *      current token are skipped without recursing into them.
 *   3. Grammar tables and rule IDs are cached in properties to avoid
 *      repeated property-chain and rule-name lookups in the hot path.
 *
 * The produced parse tree is identical to the one a naive implementation
 * would produce: WP_Parser_Node instances with token leaves.
 *
 * @TODO: Add a detailed description and list the properties that a grammar must
 *        satisfy in order to be supported by this parser (e.g., no left recursion).
 */
class WP_Parser {
	protected $grammar;
	protected $tokens;
	protected $position;

	protected $token_count;
	protected $rules;
	protected $rule_names;
	protected $fragment_ids;
	protected $lookahead;
	protected $branch_first;
	protected $highest_terminal_id;
	protected $query_rule_id;
	protected $select_statement_rule_id;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar  = $grammar;
		$this->tokens   = $tokens;
		$this->position = 0;

		$this->token_count         = count( $tokens );
		$this->rules               = $grammar->rules;
		$this->rule_names          = $grammar->rule_names;
		$this->fragment_ids        = $grammar->fragment_ids;
		$this->lookahead           = $grammar->lookahead_is_match_possible;
		$this->branch_first        = $grammar->branch_first;
		$this->highest_terminal_id = $grammar->highest_terminal_id;

		// @TODO: Make the starting rule lookup non-grammar-specific.
		$this->query_rule_id            = $grammar->get_rule_id( 'query' );
		$this->select_statement_rule_id = $grammar->get_rule_id( 'selectStatement' );
	}

	public function parse() {
		$ast = $this->parse_recursive( $this->query_rule_id );
		return false === $ast ? null : $ast;
	}

	private function parse_recursive( $rule_id ) {
		if ( $rule_id <= $this->highest_terminal_id ) {
			if ( $this->position >= $this->token_count ) {
				return false;
			}

			if ( WP_Parser_Grammar::EMPTY_RULE_ID === $rule_id ) {
				return true;
			}

			if ( $this->tokens[ $this->position ]->id === $rule_id ) {
				++$this->position;
				return $this->tokens[ $this->position - 1 ];
			}
			return false;
		}

		$branches = $this->rules[ $rule_id ];
		if ( ! $branches ) {
			return false;
		}

		$starting_position = $this->position;
		$branch_first      = $this->branch_first[ $rule_id ] ?? null;
		$current_token     = $this->tokens[ $starting_position ] ?? null;
		$current_token_id  = null !== $current_token ? $current_token->id : -1;

		// Bale out from processing the current rule if none of its branches can
		// possibly match the current token. When the token stream is exhausted,
		// the prune is skipped and terminals fail the match instead.
		if (
			null !== $current_token &&
			isset( $this->lookahead[ $rule_id ] ) &&
			! isset( $this->lookahead[ $rule_id ][ $current_token_id ] ) &&
			! isset( $this->lookahead[ $rule_id ][ WP_Parser_Grammar::EMPTY_RULE_ID ] )
		) {
			return false;
		}

		$branch_matches = false;
		$children       = array();
		foreach ( $branches as $branch_index => $branch ) {
			// Skip branches that cannot possibly match the current token.
			if (
				null !== $branch_first &&
				isset( $branch_first[ $branch_index ] ) &&
				! isset( $branch_first[ $branch_index ][ $current_token_id ] )
			) {
				continue;
			}

			$this->position = $starting_position;
			$children       = array();
			$branch_matches = true;
			foreach ( $branch as $subrule_id ) {
				$subnode = $this->parse_recursive( $subrule_id );
				if ( false === $subnode ) {
					$branch_matches = false;
					break;
				} elseif ( true === $subnode ) {
					/*
					 * The subrule was matched without actually matching a token.
					 * This means a special empty "ε" (epsilon) rule was matched.
					 * An "ε" rule in a grammar matches an empty input of 0 bytes.
					 * It is used to represent optional grammar productions.
					 */
					continue;
				} elseif ( is_array( $subnode ) ) {
					// A fragment rule matched: splice its children inline.
					foreach ( $subnode as $subnode_child ) {
						$children[] = $subnode_child;
					}
				} else {
					$children[] = $subnode;
				}
			}

			// Negative lookahead for INTO after a valid SELECT statement.
			// If we match a SELECT statement, but there is an INTO keyword after it,
			// we're in the wrong branch and need to leave matching to a later rule.
			// @TODO: Extract this to the "WP_MySQL_Parser" class, or add support
			//        for right-associative rules, which could solve this.
			//        See: https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/grammars/MySQLParser.g4#L994
			//        See: https://github.com/antlr/antlr4/issues/488
			if ( $rule_id === $this->select_statement_rule_id ) {
				$la = $this->tokens[ $this->position ] ?? null;
				if ( $la && WP_MySQL_Lexer::INTO_SYMBOL === $la->id ) {
					$branch_matches = false;
				}
			}

			if ( true === $branch_matches ) {
				break;
			}
		}

		if ( ! $branch_matches ) {
			$this->position = $starting_position;
			return false;
		}

		if ( ! $children ) {
			return true;
		}

		if ( isset( $this->fragment_ids[ $rule_id ] ) ) {
			// Fragments are inlined into the parent rule, so no node is needed.
			// The children array is spliced directly into the parent's children.
			return $children;
		}

		return new WP_Parser_Node( $rule_id, $this->rule_names[ $rule_id ], $children );
	}
}
