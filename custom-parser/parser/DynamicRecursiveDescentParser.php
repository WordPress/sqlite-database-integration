<?php
/*
@TODO:
* ✅ Tokenize MySQL Queries
* ✅ Inline fragments
* ✅ Prune the lookup tree with lookahead table

Possible exploration avenues:
* Memoize token nb/rule matches to avoid repeating work.
* Optimize the grammar to resolve ambiugities
* Generate an expanded PHP parser to optimize matching, right now we're doing a 
  whole lot of lookups
*/


function tokenizeQuery($sql) {
    $lexer = new MySQLLexer($sql);
    $tokens = [];
    do {
        $token = $lexer->getNextToken();
        $tokens[] = $token;
    } while ($token->type !== MySQLLexer::EOF);
    return $tokens;
}
class Grammar {

    public $rules;
    public $rule_names;
    public $fragment_ids;
    public $lookahead_is_match_possible = [];
    public $lowest_non_terminal_id;
    public $highest_terminal_id;

    public function __construct(array $rules)
    {
        $this->inflate($rules);
    }

    public function get_rule_name($rule_id) {
        return $this->rule_names[$rule_id];
    }

    public function get_rule_id($rule_name) {
        return array_search($rule_name, $this->rule_names);
    }

    /**
     * Grammar is a packed PHP array to minimize the file size. Every
     * rule and token is encoded as an integer. It still takes 1.2MB,
     * maybe we can do better than that with a more efficient encoding,
     * e.g. what Dennis Snell did for the HTML entity decoder.
     * Or maybe we can reduce the grammar size by factoring the rules?
     * Or perhaps we can let go of some parsing rules that SQLite cannot
     * support anyway?
     */
    private function inflate($grammar)
    {
        $this->lowest_non_terminal_id = $grammar['rules_offset'];
        $this->highest_terminal_id = $this->lowest_non_terminal_id - 1;

        foreach($grammar['rules_names'] as $rule_index => $rule_name) {
            $this->rule_names[$rule_index + $grammar['rules_offset']] = $rule_name;
            $this->rules[$rule_index + $grammar['rules_offset']] = [];
            /**
             * Treat all intermediate rules as fragments to inline before returning
             * the final parse tree to the API consumer.
             * 
             * The original grammar was too difficult to parse with rules like
             * 
             *    query ::= EOF | ((simpleStatement | beginWork) ((SEMICOLON_SYMBOL EOF?) | EOF))
             * 
             * We've  factored rules like bitExpr* to separate rules like bitExpr_zero_or_more.
             * This is super useful for parsing, but it limits the API consumer's ability to
             * reason about the parse tree.
             * 
             * The following rules as fragments:
             * 
             * * Rules starting with a percent sign ("%") – these are intermediate
             *   rules that are not part of the original grammar. They are useful
             * 
             */
            if($rule_name[0] === '%' || $rule_name[0] === 'selectOption_rr') {
                $this->fragment_ids[$rule_index + $grammar['rules_offset']] = true;
            }
        }

        $this->rules = [];
        foreach($grammar['grammar'] as $rule_index => $branches) {
            $rule_id = $rule_index + $grammar['rules_offset'];
            $this->rules[$rule_id] = $branches;
        }

        /**
         * Compute a rule => [token => true] lookup table for each rule
         * that starts with a terminal OR with another rule that already
         * has a lookahead mapping.
         * 
         * This is similar to left-factoring the grammar, even if not quite
         * the same.
         * 
         * This enables us to quickly bale out from checking branches that 
         * cannot possibly match the current token. This increased the parser
         * speed by a whooping 80%!
         * 
         * The next step could be to:
         * 
         * * Compute a rule => [token => branch[]] list lookup table and only
         *   process the branches that have a chance of matching the current token.
         * * Actually left-factor the grammar as much as possible. This, however,
         *   could inflate the serialized grammar size.
         */
        // 5 iterations seem to give us all the speed gains we can get from this.
        for ($i = 0; $i < 5; $i++) {
            foreach ($grammar['grammar'] as $rule_index => $branches) {
                $rule_id = $rule_index + $grammar['rules_offset'];
                if(isset($this->lookahead_is_match_possible[$rule_id])) {
                    continue;
                }
                $rule_lookup = [];
                $first_symbol_can_be_expanded_to_all_terminals = true;
                foreach($branches as $branch) {
                    $terminals = false;
                    $branch_starts_with_terminal = $branch[0] < $this->lowest_non_terminal_id;
                    if($branch_starts_with_terminal) {
                        $terminals = [$branch[0]];
                    } else if(isset($this->lookahead_is_match_possible[$branch[0]])) {
                        $terminals = array_keys($this->lookahead_is_match_possible[$branch[0]]);
                    }

                    if($terminals === false) {
                        $first_symbol_can_be_expanded_to_all_terminals = false;
                        break;
                    }
                    foreach($terminals as $terminal) {
                        $rule_lookup[$terminal] = true;
                    }
                }
                if ($first_symbol_can_be_expanded_to_all_terminals) {
                    $this->lookahead_is_match_possible[$rule_id] = $rule_lookup;
                }
            }
        }
    }

}

class StackFrame {
    public $rule_id;
    public $starting_position = 0;
    public $position = 0;
    public $branch_index = 0;
    public $subrule_index = 0;
    public $match = [];
    public $child_frame;
}

class DynamicRecursiveDescentParser {
    private $tokens;
    private $position;
    private Grammar $grammar;

    public function __construct(Grammar $grammar, array $tokens) {
        $this->grammar = $grammar;
        $this->tokens = $tokens;
        $this->position = 0;
    }

    public function parse() {
        $query_rule_id = $this->grammar->get_rule_id('query');
        $result = $this->parse_recursive($query_rule_id);
        return $this->expand_rule_names([$query_rule_id => $result]);
    }

    /**
     * We store the rule names as integers during parsing. This method
     * expands them back to their string representation.
     * 
     * For example, the following input parse tree:
     * 
     * [
     *     2005 => [
     *         2354 => [
     *             MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
     *         ]
     *     ]
     * ]
     * 
     * Would be expanded to:
     * 
     * [
     *     'simpleStatement' => [
     *         'selectStatement' => [
     *             MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
     *         ]
     *     ]
     * ]
     * 
     * 
     * @param mixed $parse_tree
     * @return array
     */
    private function expand_rule_names($parse_tree) {
        $expanded = [];
        foreach($parse_tree as $rule_id => $children) {
            $rule_name = $this->get_rule_name($rule_id);
            $new_rule_name = str_replace(
                array('_zero_or_one', '_zero_or_more', '_one_or_more', '_rr'),
                '',
                $rule_name
            );
            if (is_array($children)) {
                if(isset($expanded[$new_rule_name])) {
                    throw new Exception("Rule $new_rule_name already exists in the parse tree. This should never happen.");
                }
                $expanded[$new_rule_name] = [];
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $expanded[$new_rule_name][] = $this->expand_rule_names($child);
                    } else {
                        $expanded[$new_rule_name][] = $child;
                    }
                }
            }
        }
        return $expanded;
    }

    private function parse_recursive($rule_id) {
        $is_terminal = $rule_id <= $this->grammar->highest_terminal_id;
        if ($is_terminal) {
            // Inlining a $this->match($rule_id) call here speeds the
            // parser up by a whooping 10%!
            if ($this->position >= count($this->tokens)) {
                return null;
            }
    
            if ( MySQLLexer::EMPTY_TOKEN === $rule_id ) {
                return true;
            }
    
            if($this->tokens[$this->position]->type === $rule_id) {
                $this->position++;
                return $this->tokens[$this->position - 1];
            }
            return null;
        }

        $rule = $this->grammar->rules[$rule_id];
        // Bale out from processing the current branch if none of its rules can
        // possibly match the current token.
        if(isset($this->grammar->lookahead_is_match_possible[$rule_id])) {
            $token_id = $this->tokens[$this->position]->type;
            if(
                !isset($this->grammar->lookahead_is_match_possible[$rule_id][$token_id]) &&
                !isset($this->grammar->lookahead_is_match_possible[$rule_id][MySQLLexer::EMPTY_TOKEN])
            ) {
                return null;
            }
        }

        $starting_position = $this->position;

        foreach ($rule as $branch) {
            $this->position = $starting_position;
            $match = [];
            $branch_matches = true;
            foreach ($branch as $subrule_id) {
                $matched_children = $this->parse_recursive($subrule_id);
                if ($matched_children === null) {
                    $branch_matches = false;
                    break;
                } else if($matched_children === true) {
                    // ε – the rule matched without actually matching a token.
                    //     Proceed without adding anything to $match.
                    continue;
                } else if(is_array($matched_children) && count($matched_children) === 0) {
                    continue;
                }
                if (!isset($match[$subrule_id])) {
                    $match[$subrule_id] = [];
                }
                if(is_array($matched_children)) {
                    $match[$subrule_id] += $matched_children;
                } else {
                    $match[$subrule_id][] = $matched_children;
                }
            }
            if ($branch_matches === true) {
                break;
            }
        }

        if (!$branch_matches) {
            $this->position = $starting_position;
            return null;
        }

        /**
         * Flatten the matched rule fragments as if their children were direct
         * descendants of the current rule.
         * 
         * What are rule fragments?
         * 
         * When we initially parse the BNF grammar file, it has compound rules such
         * as this one:
         * 
         *      query ::= EOF | ((simpleStatement | beginWork) ((SEMICOLON_SYMBOL EOF?) | EOF))
         * 
         * Building a parser that can understand such rules is way more complex than building
         * a parser that only follows simple rules, so we flatten those compound rules into
         * simpler ones. The above rule would be flattened to:
         * 
         *      query ::= EOF | %query0
         *      %query0 ::= %%query01 %%query02
         *      %%query01 ::= simpleStatement | beginWork 
         *      %%query02 ::= SEMICOLON_SYMBOL EOF_zero_or_one | EOF
         *      EOF_zero_or_one ::= EOF | ε
         * 
         * This factorization happens in 1-ebnf-to-json.js.
         * 
         * "Fragments" are intermediate artifacts whose names are not in the original grammar.
         * They are extremely useful for the parser, but the API consumer should never have to
         * worry about them. Fragment names start with a percent sign ("%"). 
         * 
         * The code below inlines every fragment back in its parent rule.
         * 
         * We could optimize this. The current $match may be discarded later on so any inlining
         * effort here would be wasted. However, inlining seems cheap and doing it bottom-up here
         * is **much** easier than reprocessing the parse tree top-down later on.
         * 
         * The following parse tree:
         * 
         * [
         *      'query' => [
         *          [
         *              '%query01' => [
         *                  [
         *                      'simpleStatement' => [
         *                          MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
         *                      ],
         *                      '%query02' => [
         *                          [
         *                              'simpleStatement' => [
         *                                  MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
         *                          ]
         *                      ],
         *                  ]
         *              ]
         *          ]
         *      ]
         * ]
         * 
         * Would be inlined as:
         * 
         * [
         *      'query' => [
         *          [
         *              'simpleStatement' => [
         *                  MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
         *              ]
         *          ],
         *          [
         *              'simpleStatement' => [
         *                  MySQLToken(MySQLLexer::WITH_SYMBOL, 'WITH')
         *              ]
         *          ]
         *      ]
         * ]
         */
        $match_to_flatmap = [];
        $last_entry_is_fragment = false;
        foreach($match as $subrule_id => $fragment_children) {
            $is_fragment = (
                is_array($fragment_children) &&
                isset($this->grammar->fragment_ids[$subrule_id])
            );

            // Every fragment becomes a new match
            if($is_fragment) {
                $last_entry_is_fragment = true;
                $match_to_flatmap = array_merge($match_to_flatmap, $fragment_children);
                continue;
            }

            // Every non-fragment is preserved and either:
            // * Added to the last match
            // * Becomes a new match if the last match was an inlined fragment
            if($last_entry_is_fragment || count($match_to_flatmap) === 0) {
                $match_to_flatmap[] = [];
                $last_entry_is_fragment = false;
            }

            $index = count($match_to_flatmap) - 1;
            $match_to_flatmap[$index][$subrule_id] = $fragment_children;
        }

        return $match_to_flatmap;
    }

    private function get_rule_name($id)
    {
        if($id <= $this->grammar->highest_terminal_id) {
            return MySQLLexer::getTokenName($id);
        }

        return $this->grammar->get_rule_name($id);        
    }
}
