<?php
/**
 * 
 * @TODOs
 * 
 * * Support literal column names like "status", "description"
 * * Support comments (as in, ignore them except where they are a part of
 *   the query, like in CREATE TABLE queries)
 */

require __DIR__ . '/MySQLLexer.php';
require __DIR__ . '/MySQLParser.php';

$queries = [
    'simple' => 'SELECT 1',
];

foreach ($queries as $key => $query) {
    printAST(parse($query));
}
// benchmarkParser($queries['acidTest']);

die();

function benchmarkParser($query) {
    $start = microtime(true);

    for ($i = 0; $i < 500; $i++) {
        parse($query);
    }

    $end = microtime(true);
    $executionTime = ($end - $start);

    echo "Execution time: " . $executionTime . " seconds";
}

function parse($query) {
    $lexer = new MySQLLexer($query, 80019);
    $parser = new MySQLParser($lexer);
    return $parser->query();
}

function printAST(ASTNode $ast, $indent = 0) {
    echo str_repeat(' ', $indent) . $ast . PHP_EOL;
    foreach($ast->children as $child) {
        printAST($child, $indent + 2);
    }
}

function printParserTree($parser) {
    $parser->query();
    $parser->printTree();
}

function printLexerTokens($lexer) {
    while($lexer->getNextToken()) {
        echo $lexer->getToken() . PHP_EOL;
        // var_dump($lexer->getToken()->getType());
        if($lexer->getToken()->getType() === MySQLLexer::EOF) {
            break;
        }
    }
}
