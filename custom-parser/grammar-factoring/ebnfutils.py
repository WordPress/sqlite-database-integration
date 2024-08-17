"""
Source: https://www.geeksforgeeks.org/removing-direct-and-indirect-left-recursion-in-a-grammar/
"""
import json
from collections import defaultdict
from typing import Dict, List, Tuple

class NonTerminal :
    def __init__(self, name) :
        self.name = name
        self.rules = []
    def addRule(self, rule) :
        self.rules.append(rule)
    def setRules(self, rules) :
        self.rules = rules
    def getName(self) :
        return self.name
    def getRules(self) :
        return self.rules
    def printRule(self) :
        print(self.name + " -> ", end = "")
        for i in range(len(self.rules)) :
            print(self.rules[i], end = "")
            if i != len(self.rules) - 1 :
                print(" | ", end = "")
        print()
    def __str__(self) -> str:
        return self.name
         
         
class LeftRecursionEliminator:
    def __init__(self) :
        self.nonTerminals = []
 
    def addRule(self, rule) :
        nt = False
        parse = ""
 
        for i in range(len(rule)) :
            c = rule[i]
            if c == ' ' :
                if not nt :
                    newNonTerminal = NonTerminal(parse)
                    self.nonTerminals.append(newNonTerminal)
                    nt = True
                    parse = ""
                elif parse != "" :
                    self.nonTerminals[len(self.nonTerminals) - 1].addRule(parse)
                    parse = ""
            elif c != '|' and c != '-' and c != '>' :
                parse += c
        if parse != "" :
            self.nonTerminals[len(self.nonTerminals) - 1].addRule(parse)
 
    def solveNonImmediateLR(self, A, B) :
        nameA = A.getName()
        nameB = B.getName()
 
        rulesA = []
        rulesB = []
        newRulesA = []
        rulesA = A.getRules()
        rulesB = B.getRules()
 
        for rule in rulesA :
            if rule[0 : len(nameB)] == nameB :
                for rule1 in rulesB :
                    newRulesA.append(rule1 + rule[len(nameB) : ])
            else :
                newRulesA.append(rule)
        A.setRules(newRulesA)
 
    def solveImmediateLR(self, A) :
        name = A.getName()
        newName = name + "'"
 
        alphas = []
        betas = []
        rules = A.getRules()
        newRulesA = []
        newRulesA1 = []
 
        rules = A.getRules()
 
        # Checks if there is left recursion or not
        for rule in rules :
            if rule[0 : len(name)] == name :
                alphas.append(rule[len(name) : ])
            else :
                betas.append(rule)
 
        # If no left recursion, exit
        if len(alphas) == 0 :
            return
 
        if len(betas) == 0 :
            newRulesA.append(newName)
 
        for beta in betas :
            newRulesA.append(beta + newName)
 
        for alpha in alphas :
            newRulesA1.append(alpha + newName)
 
        # Amends the original rule
 
        A.setRules(newRulesA)
        newRulesA1.append("\u03B5")
 
        # Adds new production rule
        newNonTerminal = NonTerminal(newName)
        newNonTerminal.setRules(newRulesA1)
        self.nonTerminals.append(newNonTerminal)
 
    def applyAlgorithm(self) :
        size = len(self.nonTerminals)
        for i in range(size) :
            for j in range(i) :
                self.solveNonImmediateLR(self.nonTerminals[i], self.nonTerminals[j])
            self.solveImmediateLR(self.nonTerminals[i])
 
    def printRules(self) :
        for nonTerminal in self.nonTerminals :
            nonTerminal.printRule()
 

def hash_grammar(grammar):
    return { rule["name"]: rule["bnf"] for rule in grammar }

def unhash_grammar(grammar):
    return [{"name": name, "bnf": bnf} for name, bnf in grammar.items()]

def encode_as_ebnf(grammar):
  """Encodes the given grammar as an EBNF string.

  Args:
      grammar: A dictionary representing the EBNF grammar.

  Returns:
      An EBNF string representation of the grammar.
  """
  if not isinstance(grammar, dict):
      grammar = hash_grammar(grammar)
  ebnf_string = ""
  for name, productions in grammar.items():
    ebnf_string += name + " ::= "
    ebnf_string += " | ".join(
        [" ".join(symbol for symbol in production) for production in productions]
    )
    ebnf_string += "\n"

  return ebnf_string

class GrammarEncoder:
    rules_to_chars = None
    chars_to_rules = None
    encoded_grammar = None
    def index_rule(self, rule_name):
        if rule_name in self.rules_to_chars:
            return self.rules_to_chars[rule_name]
        next_char = chr(0x03B8 + len(self.rules_to_chars) * 2)
        self.rules_to_chars[rule_name] = next_char
        self.chars_to_rules[next_char] = rule_name
        return next_char

    def __init__(self, grammar_rules):
        self.rules_to_chars = {}
        self.chars_to_rules = {}
        self.grammar_rules = grammar_rules
        self.encoded_grammar = self._encode_grammar(grammar_rules)

    def _encode_grammar(self, grammar_rules):
        rules = []
        for input_rule in grammar_rules:
            name_symbol = self.index_rule(input_rule['name'])
            alternatives = " | ".join([
                "".join(self.index_rule(symbol) for symbol in branch)
                for branch in input_rule['bnf']
            ])
            rule = f"{name_symbol} -> {alternatives}"
            rules.append(rule)
        return rules
    
    def decode_alg_rules(self, nonTerminals):
        decoded_rules = {}
        for nonterminal in nonTerminals:
            name = self.decode_rule_name(nonterminal.name, required=True)
            bnf = [[self.decode_rule_name(rule) for rule in split_rules(rule)] for rule in nonterminal.rules]
            decoded_rules[name] = bnf
        return unhash_grammar(decoded_rules)

    def decode_rule_name(self, rule_name, required=False):
        lookable_name = rule_name.strip("'")
        if lookable_name not in self.chars_to_rules:
            if required:
                raise Exception(f"Missing symbol for {lookable_name}")
            else:
                return lookable_name
        prime = "" if lookable_name == rule_name else "_rr"
        decoded_name = self.chars_to_rules.get(lookable_name, lookable_name)
        return f"{decoded_name}{prime}"

def split_rules(rules_string):
    """
    Split a string of rules into a list of rules.
    AAV -> ["A", "A", "V"]
    AA'V -> ["A", "A'", "V"]
    AA'V' -> ["A", "A'", "V'"]
    A'AV -> ["A'", "A", "V"]
    """
    rules = []
    max = len(rules_string)
    i = 0
    while i < max:
        if i + 1 < max and rules_string[i+1] == "'":
            rules.append(rules_string[i:i+2])
            i += 2
        else:
            rules.append(rules_string[i])
            i += 1
    return rules


def expand_grammar(grammar):
    expanded_grammar = { rule["name"]: [] for rule in grammar }
    for rule in grammar:
        bnfs = []
        for branch in rule["bnf"]:
            new_branch = []
            for predicate in branch:
                if predicate.endswith("*"):
                    new_rule_name = predicate[:-1] + "_zero_or_more"
                    expanded_grammar[new_rule_name] = [
                        [predicate[:-1], new_rule_name],
                        [predicate[:-1]],
                        ["ε"]
                    ]
                elif predicate.endswith("+"):
                    new_rule_name = predicate[:-1] + "_one_or_more"
                    expanded_grammar[new_rule_name] = [
                        [predicate[:-1], new_rule_name],
                        [predicate[:-1]]
                    ]
                elif predicate.endswith("?"):
                    new_rule_name = predicate[:-1] + "_zero_or_one"
                    expanded_grammar[new_rule_name] = [
                        [predicate[:-1]],
                        ["ε"]
                    ]
                else:
                    new_rule_name = predicate
                # if "createTableOptions" in predicate:
                #     print(new_rule_name)
                #     print(expanded_grammar[new_rule_name])
                #     print(expanded_grammar.get(predicate[:-1], None))
                new_branch.append(new_rule_name)
            bnfs.append(new_branch)
        expanded_grammar[rule["name"]] = bnfs
    return unhash_grammar(expanded_grammar)

def remove_extra_epsilon(grammar):
    """
    Left recursion elimination algorithm moves the epsilon added in expand_grammar()
    into weird places in the new grammar. let's strip it.
    """
    new_grammar = []
    for rule in grammar:
        new_rule = {"name": rule["name"], "bnf": []}
        for branch in rule["bnf"]:
            new_branch = [*branch]
            if len(new_branch) > 1 and "ε" in new_branch:
                new_branch.remove("ε")
            new_rule["bnf"].append(new_branch)
        new_grammar.append(new_rule)
    return new_grammar

def eliminate_left_recursion(grammar):
    grammar = expand_grammar(grammar)
    enc = GrammarEncoder(grammar)
    alg = LeftRecursionEliminator()
    for rule in enc.encoded_grammar:
        alg.addRule(rule)
    alg.applyAlgorithm()
    grammar = enc.decode_alg_rules(alg.nonTerminals)

    return remove_extra_epsilon(grammar)

def factor_common_prefixes(grammar, passes=1):
    """Factors out common prefixes in the given EBNF grammar.

    Args:
        grammar: A dictionary representing the EBNF grammar.

    Returns:
        A new dictionary with common prefixes factored out.
    """

    for _ in range(passes):
        new_grammar = {}
        for rule in grammar:
            name = rule["name"]
            productions = rule["bnf"]

            # Group productions by common prefixes
            prefix_groups = {}
            for production in productions:
                prefix = tuple(production[:1])  # Consider only the first symbol as prefix for now
                if prefix in prefix_groups:
                    prefix_groups[prefix].append(production[1:])
                else:
                    prefix_groups[prefix] = [production[1:]]

            # Factor out common prefixes
            new_productions = []
            for prefix, group_productions in prefix_groups.items():
                if len(group_productions) > 1 and prefix != "ε":
                    # Create a new non-terminal for the factored prefix
                    new_name = name + "_" + "_".join(prefix)
                    new_grammar[new_name] = group_productions
                    new_productions.append(list(prefix) + [new_name])
                else:
                    # No factoring needed for single productions
                    new_productions.append(list(prefix) + group_productions[0])

            new_grammar[name] = new_productions
            grammar = unhash_grammar(new_grammar)
    return grammar


grammar_difficult = [
    { "name": "A", "bnf": [["bitExpr+"]] },
    { "name": "bitExpr", "bnf": [["bitExpr3"],["bitExpr1", "man"],["bitExpr2"],["at"]] },
    { "name": "bitExpr1", "bnf": [["bitExpr", "xor", "bitExpr"]] },
    { "name": "bitExpr2", "bnf": [["bitExpr", "plus", "bitExpr"]] },
    { "name": "bitExpr3", "bnf": [["identifier"]] },
]

def expand_branch(grammar_hash, branch_id):
    paths = defaultdict(list)

    def recursive_fn(branch_id, trace):
        is_terminal = isinstance(branch_id, str) and branch_id not in grammar_hash
        if is_terminal:
            token = branch_id
            paths[token].append(trace)
            return
        
        for i, branch in enumerate(grammar_hash[branch_id]):
            recursive_fn(
                branch[0],
                trace + [f"{branch_id}[{i}]"]
            )
    
    recursive_fn(branch_id, [])
    return dict(paths)



class ParseTableBuilder:
    def __init__(self, productions):
        self.productions = productions  # Dictionary where key is the non-terminal and value is the list of productions.
        self.non_terminals = list(productions.keys())
        self.terminals = set()  # To be computed from the grammar.
        self.first_sets = defaultdict(set)
        self.follow_sets = defaultdict(set)
        self.parse_table = defaultdict(dict)
        self.compute_terminals()
        self.compute_first_sets()
        self.compute_follow_sets()
        self.build_parsing_table()

    def compute_terminals(self):
        for rules in self.productions.values():
            for rule in rules:
                for symbol in rule:
                    if symbol not in self.non_terminals:
                        self.terminals.add(symbol)
        self.terminals.add('$')  # End of input symbol

    def compute_first_sets(self):
        for non_terminal in self.non_terminals:
            self.first_sets[non_terminal] = self.compute_first(non_terminal)

    def compute_first(self, non_terminal):
        first = set()
        for rule in self.productions[non_terminal]:
            if rule[0] in self.terminals:
                first.add(rule[0])
            else:
                for symbol in rule:
                    if symbol in self.terminals:
                        first.add(symbol)
                        break
                    else:
                        symbol_first = self.compute_first(symbol)
                        first.update(symbol_first - {'ε'})
                        if 'ε' not in symbol_first:
                            break
                else:
                    first.add('ε')
        return first

    def compute_follow_sets(self):
        self.follow_sets[self.non_terminals[0]].add('$')  # Start symbol follow set includes end of input
        changed = True
        while changed:
            changed = False
            for non_terminal in self.non_terminals:
                for rule in self.productions[non_terminal]:
                    follow_temp = self.follow_sets[non_terminal].copy()
                    for symbol in reversed(rule):
                        if symbol.isupper():  # Non-terminal
                            follow_size_before = len(self.follow_sets[symbol])
                            self.follow_sets[symbol].update(follow_temp)
                            if 'ε' in self.first_sets[symbol]:
                                follow_temp.update(self.first_sets[symbol] - {'ε'})
                            else:
                                follow_temp = self.first_sets[symbol].copy()
                            if len(self.follow_sets[symbol]) > follow_size_before:
                                changed = True
                        else:
                            follow_temp = {symbol}

    def build_parsing_table(self):
        for non_terminal in self.non_terminals:
            for rule in self.productions[non_terminal]:
                first = self.compute_first_for_rule(rule)
                for terminal in first:
                    if terminal != 'ε':
                        if terminal in self.parse_table[non_terminal]:
                            print(f"Conflict detected for {non_terminal} with terminal {terminal}.")
                        self.parse_table[non_terminal][terminal] = rule
                if 'ε' in first:
                    for terminal in self.follow_sets[non_terminal]:
                        if terminal in self.parse_table[non_terminal]:
                            print(f"Conflict detected for {non_terminal} with terminal {terminal}.")
                        self.parse_table[non_terminal][terminal] = rule

    def compute_first_for_rule(self, rule):
        first = set()
        for symbol in rule:
            if symbol in self.terminals:
                first.add(symbol)
                break
            else:
                symbol_first = self.first_sets[symbol]
                first.update(symbol_first - {'ε'})
                if 'ε' not in symbol_first:
                    break
        else:
            first.add('ε')
        return first

    def display_parsing_table(self):
        print("Parsing Table:")
        print(f"{'Non-Terminal':<15} {'Terminal':<30} {'Production'}")
        for non_terminal in self.parse_table:
            for terminal in self.parse_table[non_terminal]:
                print(f"{non_terminal:<15} {terminal:<10} {' -> '.join(self.parse_table[non_terminal][terminal])}")


def get_terminals(productions):
    non_terminals = list(productions.keys())
    terminals = set()
    for rules in productions.values():
        for rule in rules:
            for symbol in rule:
                if symbol not in non_terminals:
                    terminals.add(symbol)
    
    return terminals, non_terminals


# Define a function to find the common prefix
def find_common_prefix(strings: List[List[str]]) -> Tuple[List[str], List[List[str]]]:
    if not strings:
        return [], []
    
    prefix = strings[0]
    for s in strings[1:]:
        # Find the shortest common prefix between current prefix and the next string
        i = 0
        while i < len(prefix) and i < len(s) and prefix[i] == s[i]:
            i += 1
        prefix = prefix[:i]
        if not prefix:
            break

    # Separate the strings by the common prefix
    if prefix:
        remaining = [s[len(prefix):] if len(s) > len(prefix) else [''] for s in strings]
    else:
        remaining = strings
    
    return prefix, remaining

# Left-factoring function
def left_factor(grammar: Dict[str, List[List[str]]]) -> Dict[str, List[List[str]]]:
    new_grammar = {}
    new_non_terminal_index = 1

    def get_new_non_terminal():
        nonlocal new_non_terminal_index
        new_non_terminal = f"X{new_non_terminal_index}"
        new_non_terminal_index += 1
        return new_non_terminal

    for non_terminal, productions in grammar.items():
        common_prefix, remaining_productions = find_common_prefix(productions)
        
        if common_prefix:
            new_non_terminal = get_new_non_terminal()
            new_grammar[non_terminal] = [common_prefix + [new_non_terminal]]
            
            # Handle epsilon (empty production) case
            new_grammar[new_non_terminal] = [rp if rp else [''] for rp in remaining_productions]
        else:
            new_grammar[non_terminal] = productions

    return new_grammar

# Apply left-factoring across the grammar
def left_factor_grammar(productions: Dict[str, List[List[str]]]) -> Dict[str, List[List[str]]]:
    new_productions = productions.copy()
    
    # Left-factor within each non-terminal
    factored_grammar = left_factor(new_productions)
    
    # Apply left-factoring across different non-terminals
    while True:
        changed = False
        for non_terminal, production_list in factored_grammar.items():
            for i, production in enumerate(production_list):
                if len(production) == 1 and production[0] in factored_grammar:
                    # Inline the non-terminal if it's a single non-terminal production
                    factored_grammar[non_terminal][i:i+1] = factored_grammar[production[0]]
                    changed = True
        if not changed:
            break
    
    return factored_grammar

def normalize_epsilons(productions):
    """
    Move epsilons to the last position in the production rules.
    Eliminate multiple rules that all contain just the epsilon symbol.
    """
    new_productions = {}
    for non_terminal, rules in productions.items():
        new_rules = []
        has_epsilon = False
        for rule in rules:
            if 'ε' in rule and len(rule) > 1:
                new_rules.append([symbol for symbol in rule if symbol != 'ε'])
            elif rule == ['ε']:
                has_epsilon = True
            else:
                new_rules.append(rule)
        if has_epsilon:
            new_rules.append(['ε'])
        new_productions[non_terminal] = new_rules
    return new_productions


def deduplicate(productions):
    """
    Merges all non-terminals that have the same right-hand side and
    refactors all references to them.
    """
    substitutions = {}
    new_productions = {}
    for non_terminal, rules in productions.items():
        key = tuple([tuple(rule) for rule in rules])
        if key in substitutions:
            substitutions[non_terminal] = substitutions[key]
        else:
            substitutions[key] = non_terminal
            new_productions[non_terminal] = rules
    for non_terminal, rules in new_productions.items():
        new_productions[non_terminal] = [
            [substitutions.get(symbol, symbol) for symbol in rule]
            for rule in rules
        ]
    return new_productions

def next_step_lookup_table(hashed_grammar):
    lookup = defaultdict(lambda: defaultdict(list))
    terminals = defaultdict(set)
    while len(terminals) < len(hashed_grammar):
        for name, branch in hashed_grammar.items():
            for i, subrule in enumerate(branch):
                if isinstance(subrule, str):
                    raise Exception(f"Invalid subrule {subrule}")
                first = subrule[0]
                is_terminal = first not in hashed_grammar
                if is_terminal:
                    terminals[name].add(first)
                elif first in terminals:
                    terminals[name] = terminals[name].union(terminals[first])
                    for terminal in terminals[first]:
                        if i not in lookup[name][terminal]:
                            lookup[name][terminal].append(i)
    return lookup

# Example Grammar that needs left-factoring
# productions = {
#     'S': [['ad', 'Ar', 'B'], ['ad', 'Ar', 'C'], ['b', 'B']],
#     'A': [['d'], ['ε']],
#     'B': [['e'], ['f']],
#     'C': [['g']]
# }

# with open("MySQLParser-factored.json") as fp:
#     productions = hash_grammar(json.load(fp))

# import json
# print(json.dumps(next_step_lookup_table(productions)))
# exit(0)


# productions = left_factor_grammar(productions)
# for non_terminal, rules in productions.items():
#     print(f"{non_terminal} -> {' | '.join([' '.join(rule) for rule in rules])}")

# productions = normalize_epsilons(productions)
# productions = deduplicate(productions)

# pt = ParseTableBuilder(productions)
# pt.display_parsing_table()

# Print the result
# for non_terminal, rules in productions.items():
#     print(f"{non_terminal} -> {' | '.join([' '.join(rule) for rule in rules])}")

# # print(len(productions))
# # eliminator.display()
# exit(0)


# # print(encode_as_ebnf(grammar))
# import json

# print(json.dumps(expand_branch(gh, "simpleExpr"), indent=2))
# # grammar = Grammar()
# # grammar.addRule("A -> B+")
# # grammar.addRule("B+ -> B | B B+")
# # grammar.addRule("B -> c | d | E")
# # grammar.applyAlgorithm()
# # grammar.printRules()