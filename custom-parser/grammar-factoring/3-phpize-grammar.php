<?php

if($argc < 2) {
    echo "Usage: php $argv[0] <grammar.json>\n";
    exit(1);
}

function export_as_php_var($var) {
    if(is_array($var)) {
        $array_notation = "[";
        $keys = array_keys($var);
        $last_key = end($keys);
        $export_keys = json_encode(array_keys($var)) !== json_encode(range(0, count($var) - 1));
        foreach($var as $key => $value) {
            if($export_keys) {
                $array_notation .= var_export($key, true) . "=>";
            }
            $array_notation .= export_as_php_var($value);
            if($key !== $last_key) {
                $array_notation .= ",";
            }
        }
        $array_notation .= "]";
        return $array_notation;
    }
    return var_export($var, true);
}

$grammar = json_decode(file_get_contents($argv[1]), true);
require_once __DIR__ . '/../parser/MySQLLexer.php';

// Lookup tables
$rules_offset = 2000;
$rule_id_by_name = [];
$rule_index_by_name = [];
foreach ($grammar as $rule) {
    $rules_ids[] = $rule["name"];
    $rule_index_by_name[$rule["name"]] = (count($rules_ids) - 1);
    $rule_id_by_name[$rule["name"]] = $rule_index_by_name[$rule["name"]] + $rules_offset;
    $compressed_grammar[$rule["name"]] = [];
}

// Convert rules ids and token ids to integers
$compressed_grammar = [];
foreach($grammar as $rule) {
    $new_branches = [];
    foreach($rule["bnf"] as $branch) {
        $new_branch = [];
        foreach($branch as $i => $name) {
            $is_terminal = !isset($rule_id_by_name[$name]);
            if($is_terminal) {
                $new_branch[] = MySQLLexer::getTokenId($name);
            } else {
                // Use rule id to avoid conflicts with token ids
                $new_branch[] = $rule_id_by_name[$name];
            }
        }
        $new_branches[] = $new_branch;
    }
    // Use rule index
    $compressed_grammar[$rule_index_by_name[$rule["name"]]] = $new_branches;
}

// Compress the fragment rules names – they take a lot of disk space and are
// inlined in the final parse tree anyway.
$last_fragment = 1;
foreach($rules_ids as $id => $name) {
    if(
        $name[0] === '%' || 
        str_ends_with($name, '_zero_or_one') || 
        str_ends_with($name, '_zero_or_more') || 
        str_ends_with($name, '_one_or_more')
    ) {
        $rules_ids[$id] = '%f' . $last_fragment;
        ++$last_fragment;
    }
}

$full_grammar = [
    "rules_offset" => $rules_offset,
    "rules_names" => $rules_ids,
    "grammar" => $compressed_grammar
];

$php_array = export_as_php_var($full_grammar);
echo "<?php\nreturn " . $php_array . ";";
