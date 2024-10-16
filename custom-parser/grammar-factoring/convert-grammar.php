<?php

// Convert the original MySQLParser.g4 grammar to a JSON format.
// The grammar is also flattened and expanded to an ebnf-to-json-like format.
// Additionally, it captures version specifiers to be used in the parser.

// Recursive pattern to capture matching parentheses, including nested ones.
const PARENS_REGEX = '\((?:[^()]+|(?R))*+\)[?*+]?';

// 1. Parse MySQLParser.g4 grammar.
$grammar = file_get_contents(__DIR__ . '/MySQLParser.g4');
$grammar = preg_replace('~/\*[\s\S]*?\*/|//.*$~m', '', $grammar); // remove comments
$grammar = preg_replace('/^.*?\s(?=\w+:)/ms', '', $grammar, 1); // remove all until first rule
$parts = explode(';', $grammar); // split grammar by ";"

function process_rule(string $rule) {
	if (preg_match('/^[\w%?*+]+$/', $rule)) {
		return $rule;
	}

	$parens_regex = PARENS_REGEX;

	// extract rule branches (split by | not inside parentheses)
	preg_match_all("/((?:[^()|]|$parens_regex)+)/", $rule, $matches);
	$branches = $matches[0];
	$subrules = [];
	foreach ($branches as $branch) {
		$branch = trim($branch);

		// extract version specifiers (like "{serverVersion >= 80000}?")
		$versions = null;
		if (preg_match('/^\{(.+?)}\?\s+(.*)$/s', $branch, $matches)) {
			$versions = $matches[1];
			$branch = $matches[2];
		}

		// remove named accessors
		$branch = preg_replace('/\w+\s*=\s*/', '', $branch);

		// remove labels
		$branch = preg_replace('/#\s*\w+/', '', $branch);

		// extract branch sequence (split by whitespace not inside parentheses)
		preg_match_all("/(?:[^()\s]|$parens_regex)+/s", $branch, $matches);
		$sequence = [];
		foreach ($matches[0] as $part) {
			// extract subrule (inside parentheses), capture quantifiers (?, *, +)
			if ($part[0] === '(') {
				$last = $part[strlen($part) - 1];
				$quantifier = null;
				if ($last === '?' || $last === '*' || $last === '+') {
					$part = substr($part, 0, -1);
					$quantifier = $last;
				}
				$subrule = ['value' => process_rule(substr($part, 1, -1))];
				if ($quantifier !== null) {
					$subrule['quantifier'] = $quantifier;
				}
				$sequence[] = $subrule;
			} else {
				$sequence[] = process_rule($part);
			}
		}
		$subrule = $versions !== null ? [['value' => $sequence, 'versions' => $versions]] : $sequence;
		if (count($subrule) > 0) {
			$subrules[] = $subrule;
		}
	}
	return $subrules;
}

$rules = [];
foreach ($parts as $i => $part) {
	$part = trim($part);
	if ($part === '') {
		continue;
	}

	$rule_parts = explode(':', $part);
	if (count($rule_parts) !== 2) {
		throw new Exception('Invalid rule: ' . $part);
	}
	$rules[trim($rule_parts[0])] = process_rule($rule_parts[1]);
}

//echo json_encode($rules, JSON_PRETTY_PRINT); return;

// 2. Flatten the grammar.
$flat = [];
function flatten_rule($name, $rule) {
	global $flat;

	if (is_string($rule)) {
		return $rule;
	}

	$values = isset($rule['value']) ? $rule['value'] : $rule;
	$branches = [];
	foreach ($values as $i => $branch) {
		$branches[] = flatten_rule($name . $i, $branch);
	}

	if (isset($rule['value'])) {
		$new_name = '%' . $name;
		$flat[] = array_merge($rule, ['name' => $new_name, 'value' => $branches]);
		return $new_name . ($rule['quantifier'] ?? '');
	} else {
		return $branches;
	}
}

$flat_rules = [];
foreach ($rules as $name => $rule) {
	$flat_rules[] = ['name' => $name, 'value' => flatten_rule($name, $rule)];
	$flat_rules = array_merge($flat_rules, $flat);
	$flat = [];
}

//echo json_encode($flat_rules, JSON_PRETTY_PRINT); return;

// 3. Expand the grammar.
$expanded = [];
function expand($value) {
	global $expanded;

	$last = $value[strlen($value) - 1];
	$name = substr($value, 0, -1);
	if ($last === '?') {
		$expanded[] = ['name' => $value, 'value' => [[$name], ["ε"]]];
	} elseif ($last === '*') {
		$expanded[] = ['name' => $value, 'value' => [[$name, $value], [$name], ["ε"]]];
	} elseif ($last === '+') {
		$expanded[] = ['name' => $value, 'value' => [[$name, $value], [$name]]];
	}
}

foreach ($flat_rules as $rule) {
	foreach ($rule['value'] as $i => $branch) {
		$values = is_string($branch) ? [$branch] : $branch;
		foreach ($values as $value) {
			expand($value);
		}
	}

	if (isset($rule['quantifier'])) {
		$value = $rule['name'] . $rule['quantifier'];
		expand($value);
		unset($rule['quantifier']);
	}

	$expanded[$rule['name']] = $rule;
}

//echo json_encode($expanded, JSON_PRETTY_PRINT); return;

// 4. Unify naming with the ebnf-to-json format.
function convert_name($name) {
	$name_quantifier = $name[strlen($name) - 1];
	if ($name_quantifier === '?') {
		return substr($name, 0, -1) . '_zero_or_one';
	} elseif ($name_quantifier === '*') {
		return substr($name, 0, -1) . '_zero_or_more';
	} elseif ($name_quantifier === '+') {
		return substr($name, 0, -1) . '_one_or_more';
	}
	return $name;
}

$unified = [];
foreach ($expanded as $rule) {
	$rule['name'] = convert_name($rule['name']);
	foreach ($rule['value'] as $i => $branch) {
		if (is_string($branch)) {
			$rule['value'][$i] = convert_name($rule['value'][$i]);
		} else {
			foreach ($branch as $j => $subrule) {
				if (is_string($subrule)) {
					$rule['value'][$i][$j] = convert_name($rule['value'][$i][$j]);
				}
			}
		}
	}

	if (is_string($rule['value'][0] ?? null)) {
		$rule['value'] = [$rule['value']];
	}

	$rule['bnf'] = $rule['value'];
	unset($rule['value']);
	$unified[] = $rule;
}

echo json_encode($unified, JSON_PRETTY_PRINT);
