<?php

// Throw exception if anything fails.
set_error_handler(function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/../../custom-parser/parser/DynamicRecursiveDescentParser.php';
require_once __DIR__ . '/../../custom-parser/parser/MySQLLexer.php';

$handle = fopen(__DIR__ . '/data/queries.csv', 'r');

$i = 1;
$start = microtime(true);
while (($query = fgetcsv($handle)) !== false) {
	$query = $query[0];

	$tokens = tokenizeQuery($query);
	if (empty($tokens)) {
		throw new Exception('Failed to tokenize query: ' . $query);
	}
	$i++;
}

echo "Tokenized $i queries in ",  microtime(true) - $start, 's', PHP_EOL;

// Add some manual tests
$tests = [
	/**
	 * Numbers vs. identifiers:
	 *
	 * In MySQL, when an input matches both a number and an identifier, the number always wins.
	 * However, when the number is followed by a non-numeric identifier-like character, it is
	 * considered an identifier... unless it's a float number, which ignores subsequent input.
	 */

	// INT numbers vs. identifiers
	'123' => ['INT_NUMBER', 'EOF'],
	'123abc' => ['IDENTIFIER', 'EOF'], // identifier

	// BIN numbers vs. identifiers
	'0b01' => ['BIN_NUMBER', 'EOF'],
	'0b01xyz' => ['IDENTIFIER', 'EOF'], // identifier
	"b'01'" => ['BIN_NUMBER', 'EOF'],
	"b'01xyz'" => ['BIN_NUMBER', 'IDENTIFIER', 'INVALID_INPUT', 'EOF'],

	// HEX numbers vs. identifiers
	'0xab01' => ['HEX_NUMBER', 'EOF'],
	'0xab01xyz' => ['IDENTIFIER', 'EOF'], // identifier
	"x'ab01'" => ['HEX_NUMBER', 'EOF'],
	"x'ab01xyz'" => ['HEX_NUMBER', 'IDENTIFIER', 'INVALID_INPUT', 'EOF'],

	// DECIMAL numbers vs. identifiers
	'123.456' => ['DECIMAL_NUMBER', 'EOF'],
	'.123' => ['DECIMAL_NUMBER', 'EOF'],
	'123.456abc' => ['DECIMAL_NUMBER', 'IDENTIFIER', 'EOF'], // not identifier
	'.123abc' => ['DECIMAL_NUMBER', 'IDENTIFIER', 'EOF'], // not identifier

	// FLOAT numbers vs. identifiers
	'1e10' => ['FLOAT_NUMBER', 'EOF'],
	'1e+10' => ['FLOAT_NUMBER', 'EOF'],
	'1e-10' => ['FLOAT_NUMBER', 'EOF'],
	'1e10abc' => ['FLOAT_NUMBER', 'IDENTIFIER', 'EOF'], // not identifier (this differs from INT/BIN/HEX numbers)
	'1e+10abc' => ['FLOAT_NUMBER', 'IDENTIFIER', 'EOF'], // not identifier
	'1e-10abc' => ['FLOAT_NUMBER', 'IDENTIFIER', 'EOF'], // not identifier
];

$failures = 0;
foreach ($tests as $input => $expected) {
	$tokens = tokenizeQuery($input);
	$token_names = array_map(function ($token) {
		return $token->getName();
	}, $tokens);
	if ($token_names !== $expected) {
		$failures += 1;
		echo "\nFailed test for input: $input\n";
		echo "  Expected: ", implode(', ', $expected), "\n";
		echo "  Actual:   ", implode(', ', $token_names), "\n";
	}
}
if ($failures > 0) {
	echo "\n$failures tests failed!\n";
}

