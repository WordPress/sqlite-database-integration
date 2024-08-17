<?php

require_once __DIR__ . '/MySQLLexer.php';
require_once __DIR__ . '/DynamicRecursiveDescentParser.php';
require_once __DIR__ . '/SQLiteDriver.php';

$query = <<<SQL
WITH
    mytable AS (select 1 as a, `b`.c from dual),
    mytable2 AS (select 1 as a, `b`.c from dual) 
SELECT HIGH_PRIORITY SQL_SMALL_RESULT DISTINCT
	CONCAT("a", "b"),
	UPPER(z),
    DATE_FORMAT(col_a, '%Y-%m-%d %H:%i:%s') as formatted_date,
    DATE_ADD(col_b, INTERVAL 5 MONTH ) as date_plus_one,
	col_a
FROM 
my_table FORCE INDEX (`idx_department_id`),
(SELECT `mycol`, 997482686 FROM "mytable") as subquery
LEFT JOIN (SELECT a_column_yo from mytable) as t2 
    ON (t2.id = mytable.id AND t2.id = 1)
WHERE 1 = 3
GROUP BY col_a, col_b
HAVING 1 = 2
UNION SELECT * from table_cde
ORDER BY col_a DESC, col_b ASC
FOR UPDATE;
;
SQL;

// $query = <<<SQL
// SELECT CONCAT(a.a,"b",'c','d');
// SQL;

$grammar_data = include "./grammar.php";
$grammar = new Grammar($grammar_data);
// print_r(tokenizeQuery($query));
// die();
$parser = new DynamicRecursiveDescentParser($grammar, tokenizeQuery($query));
$parseTree = $parser->parse();
// echo 'a';
print_r($parseTree);
// die();
$expr = translateQuery($parseTree);
echo SQLiteQueryBuilder::stringify($expr) . '';
die();
// $transformer = new SQLTransformer($parseTree, 'sqlite');
// $expression = $transformer->transform();
// print_r($expression);

class ParseTreeTools {
    public static function hasChildren($ast, $symbol_id=null) {
        $symbol_name = null !== $symbol_id ? MySQLLexer::getTokenName($symbol_id) : null;
        foreach($ast as $child) {
            if($symbol_name !== null) {
                if(array_key_exists($symbol_name, $child)) {
                    return true;
                }
                continue;
            }
            if(count($child)) {
                return true;
            }
        }
        return false;
    }
    public static function countChildren($ast, $key=null) {
        $count = 0;
        foreach($ast as $child) {
            if($key !== null) {
                if(array_key_exists($key, $child)) {
                    $count++;
                }
                continue;
            }
            $count += count($child);
        }
        return $count;
    }
}

function translateQuery($ast, $rule_name=null) {
    if($ast === null) {
        return null;
    }
    if(null === $rule_name) {
        if(array_key_exists('query', $ast)) {
            return translateQuery($ast['query'], 'query');
        }
        return null;
    }

    if(count($ast) === 1 && $ast[0] instanceof MySQLToken) {
        $token = $ast[0];
        switch ($token->type) {
            case MySQLLexer::EOF:
                return new SQLiteExpression([]);

            case MySQLLexer::IDENTIFIER:
                return new SQLiteExpression([
                    SQLiteTokenFactory::identifier(
                        trim($token->text, '`"')
                    )
                ]);

            default:
                return new SQLiteExpression([
                    SQLiteTokenFactory::raw($token->text)
                ]);
        }
    }

    switch($rule_name) {
        case 'indexHintList':
            // SQLite doesn't support index hints. Let's
            // skip them.
            return null;

        case 'selectOption':
            $all_options = [];
            $options_stack = [$ast];
            while (!empty($options_stack)) {
                $option_ast = array_pop($options_stack);
                foreach ($option_ast as $option) {
                    foreach ($option as $sub_rule => $sub_ast) {
                        if ($sub_rule === 'selectOption') {
                            array_push($options_stack, $sub_ast);
                        } else {
                            $option = translateQuery($sub_ast, $sub_rule);
                            $all_options[] = $option->elements[0]->value;
                        }
                    }
                }
            }
            $emit_options = [];
            if(in_array('ALL', $all_options)) {
                $emit_options[] = SQLiteTokenFactory::raw('ALL');
            }
            if(in_array('DISTINCT', $all_options) || in_array('DISTINCTROW', $all_options)) {
                $emit_options[] = SQLiteTokenFactory::raw('DISTINCT');
            }
            if(in_array('SQL_CALC_FOUND_ROWS', $all_options)) {
                // we'll need to run the current SQL query without any
                // LIMIT clause, and then substitute the FOUND_ROWS()
                // function with a literal number of rows found.            
            }
            return new SQLiteExpression($emit_options);

        case 'fromClause':
            // Skip `FROM DUAL`. We only care about a singular 
            // FROM DUAL statement, as FROM mytable, DUAL is a syntax
            // error.
            if(
                ParseTreeTools::hasChildren($ast, MySQLLexer::DUAL_SYMBOL) && 
                !ParseTreeTools::hasChildren($ast, 'tableReferenceList')
            ) {
                return null;
            }
        case 'interval':
        case 'intervalTimeStamp':
        case 'bitExpr':
        case 'boolPri':
        case 'lockStrengh':
        case 'orderList':
        case 'simpleExpr':
        case 'columnRef':
        case 'exprIs':
        case 'exprAnd':
        case 'primaryExprCompare':
        case 'fieldIdentifier':
        case 'dotIdentifier':
        case 'identifier':
        case 'literal':
        case 'joinedTable':
        case 'nullLiteral':
        case 'boolLiteral':
        case 'numLiteral':
        case 'textLiteral':
        case 'predicate':
        case 'predicateExprBetween':
        case 'primaryExprPredicate':
        case 'pureIdentifier':
        case 'unambiguousIdentifier':
        case 'qualifiedIdentifier':
        case 'query':
        case 'queryExpression':
        case 'queryExpressionBody':
        case 'queryExpressionParens':
        case 'queryPrimary':
        case 'querySpecification':
        case 'selectAlias':
        case 'selectItem':
        case 'selectItemList':
        case 'selectStatement':
        case 'simpleExprColumnRef':
        case 'simpleExprFunction':
        case 'outerJoinType':
        case 'simpleExprSubQuery':
        case 'simpleExprLiteral':
        case 'compOp':
        case 'simpleExprList':
        case 'simpleStatement':
        case 'subquery':
        case 'exprList':
        case 'expr':
        case 'tableReferenceList':
        case 'tableReference':
        case 'tableRef':
        case 'tableAlias':
        case 'tableFactor':
        case 'singleTable':
        case 'udfExprList':
        case 'udfExpr':
        case 'withClause':
        case 'whereClause':
        case 'commonTableExpression':
        case 'derivedTable':
        case 'columnRefOrLiteral':
        case 'querySpecOption':
        case 'orderClause':
        case 'groupByClause':
        case 'lockingClauseList':
        case 'lockingClause':
        case 'havingClause':
        case 'direction':
        case 'orderExpression':
        case 'runtimeFunctionCall':
            $child_expressions = [];
            foreach($ast as $child) {
                foreach ($child as $sub_rule => $sub_ast) {
                    $child_expressions[] = translateQuery($sub_ast, $sub_rule);
                }
            }
            return new SQLiteExpression($child_expressions);

        case 'textStringLiteral':
            return new SQLiteExpression([
                ParseTreeTools::hasChildren($ast, MySQLLexer::DOUBLE_QUOTED_TEXT) ? 
                    SQLiteTokenFactory::doubleQuotedValue($ast[0]['DOUBLE_QUOTED_TEXT'][0]->text) : false,
                ParseTreeTools::hasChildren($ast, MySQLLexer::SINGLE_QUOTED_TEXT) ? 
                    SQLiteTokenFactory::raw($ast[0]['SINGLE_QUOTED_TEXT'][0]->text) : false,
            ]);

        case 'functionCall':
            if(isset($ast[0]['pureIdentifier'])) {
                $name = $ast[0]['pureIdentifier'][0]['IDENTIFIER'][0]->text;
                return translateFunctionCall($name, $ast[0]['udfExprList']);
            }
            throw new Exception('Unsupported function call AST: ' . print_r($ast, true));

        default:
            // var_dump(count($ast->children));
            // foreach($ast->children as $child) {
            //     var_dump(get_class($child));
            //     echo $child->getText();
            //     echo "\n\n";
            // }
            return new SQLiteExpression([
                SQLiteTokenFactory::raw(
                    $rule_name
                )
            ]);
    }
}

function translateFunctionCall($name, $args_ast): SQLiteExpression
{
    $args = [];
    foreach($args_ast as $k => $arg) {
        if($k === 0) {
            $args[] = translateQuery([$arg], 'udfExpr');
        } else {
            $args[] = translateQuery($arg['udfExpr'], 'udfExpr');
        }
    }
    switch (strtoupper($name)) {
        case 'ABS':
        case 'ACOS':
        case 'ASIN':
        case 'ATAN':
        case 'ATAN2':
        case 'COS':
        case 'DEGREES':
        case 'TRIM':
        case 'EXP':
        case 'MAX':
        case 'MIN':
        case 'FLOOR':
        case 'RADIANS':
        case 'ROUND':
        case 'SIN':
        case 'SQRT':
        case 'TAN':
        case 'TRUNCATE':
        case 'RANDOM':
        case 'PI':
        case 'LTRIM':
        case 'RTRIM':
            return SQLiteTokenFactory::createFunction($name, $args);

        case 'CEIL':
        case 'CEILING':
            return SQLiteTokenFactory::createFunction('CEIL', $args);

        case 'COT':
            return new Expression([
                SQLiteTokenFactory::raw('1 / '),
                SQLiteTokenFactory::createFunction('TAN', $args)
            ]);
            
        case 'LN':
        case 'LOG':
        case 'LOG2':
            return SQLiteTokenFactory::createFunction('LOG', $args);

        case 'LOG10':
            return SQLiteTokenFactory::createFunction('LOG10', $args);

        // case 'MOD':
        //     return $this->transformBinaryOperation([
        //         'operator' => '%',
        //         'left' => $args[0],
        //         'right' => $args[1]
        //     ]);

        case 'POW':
        case 'POWER':
            return SQLiteTokenFactory::createFunction('POW', $args);
        
        // String functions
        case 'ASCII':
            return SQLiteTokenFactory::createFunction('UNICODE', $args);
        case 'CHAR_LENGTH':
        case 'LENGTH':
            return SQLiteTokenFactory::createFunction('LENGTH', $args);
        case 'CONCAT':
            $concated = [SQLiteTokenFactory::raw("(")];
            foreach ($args as $k => $arg) {
                $concated[] = $arg;
                if ($k < count($args) - 1) {
                    $concated[] = SQLiteTokenFactory::raw("||");
                }
            }
            $concated[] = SQLiteTokenFactory::raw(")");
            return new SQLiteExpression($concated);
        // case 'CONCAT_WS':
        //     return new Expression([
        //         SQLiteTokenFactory::raw("REPLACE("),
        //         implode(" || ", array_slice($args, 1)),
        //         SQLiteTokenFactory::raw(", '', "),
        //         $args[0],
        //         SQLiteTokenFactory::raw(")")
        //     ]);
        case 'INSTR':
            return SQLiteTokenFactory::createFunction('INSTR', $args);
        case 'LCASE':
        case 'LOWER':
            return SQLiteTokenFactory::createFunction('LOWER', $args);
        case 'LEFT':
            return SQLiteTokenFactory::createFunction('SUBSTR', [
                $args[0],
                '1',
                $args[1]
            ]);
        case 'LOCATE':
            return SQLiteTokenFactory::createFunction('INSTR', [
                $args[1],
                $args[0]
            ]);
        case 'REPEAT':
            return new Expression([
                SQLiteTokenFactory::raw("REPLACE(CHAR(32), ' ', "),
                $args[0],
                SQLiteTokenFactory::raw(")")
            ]);

        case 'REPLACE':
            return new Expression([
                SQLiteTokenFactory::raw("REPLACE("),
                implode(", ", $args),
                SQLiteTokenFactory::raw(")")
            ]);
        case 'RIGHT':
            return new Expression([
                SQLiteTokenFactory::raw("SUBSTR("),
                $args[0],
                SQLiteTokenFactory::raw(", -("),
                $args[1],
                SQLiteTokenFactory::raw("))")
            ]);
        case 'SPACE':
            return new Expression([
                SQLiteTokenFactory::raw("REPLACE(CHAR(32), ' ', '')")
            ]);
        case 'SUBSTRING':
        case 'SUBSTR':
            return SQLiteTokenFactory::createFunction('SUBSTR', $args);
        case 'UCASE':
        case 'UPPER':
            return SQLiteTokenFactory::createFunction('UPPER', $args);
            
        // case 'ADDDATE':
        // case 'DATE_ADD':
        //     return new Expression([
        //         SQLiteTokenFactory::raw("DATETIME("),
        //         $args[0],
        //         SQLiteTokenFactory::raw(", '+'"),
        //         $args[1],
        //         SQLiteTokenFactory::raw(" days')")
        //     ]);
        // case 'DATE_SUB':
        //     return new Expression([
        //         SQLiteTokenFactory::raw("DATETIME("),
        //         $args[0],
        //         SQLiteTokenFactory::raw(", '-'"),
        //         $args[1],
        //         SQLiteTokenFactory::raw(" days')")
        //     ]);
        case 'DATE_FORMAT':
            $mysql_date_format_to_sqlite_strftime = array(
                '%a' => '%D',
                '%b' => '%M',
                '%c' => '%n',
                '%D' => '%jS',
                '%d' => '%d',
                '%e' => '%j',
                '%H' => '%H',
                '%h' => '%h',
                '%I' => '%h',
                '%i' => '%M',
                '%j' => '%z',
                '%k' => '%G',
                '%l' => '%g',
                '%M' => '%F',
                '%m' => '%m',
                '%p' => '%A',
                '%r' => '%h:%i:%s %A',
                '%S' => '%s',
                '%s' => '%s',
                '%T' => '%H:%i:%s',
                '%U' => '%W',
                '%u' => '%W',
                '%V' => '%W',
                '%v' => '%W',
                '%W' => '%l',
                '%w' => '%w',
                '%X' => '%Y',
                '%x' => '%o',
                '%Y' => '%Y',
                '%y' => '%y',
            );
            // @TODO: Implement as user defined function to avoid 
            //        rewriting something that may be an expression as a string
            $format = $args[1]->elements[0]->value;
            $new_format = strtr( $format, $mysql_date_format_to_sqlite_strftime );

            return SQLiteTokenFactory::createFunction(
                'STRFTIME',
                [
                    new Expression([SQLiteTokenFactory::raw($new_format)]),
                    new Expression([$args[0]])
                ]
            );
        case 'DATEDIFF':
            return new Expression([
                SQLiteTokenFactory::createFunction('JULIANDAY', [$args[0]]),
                SQLiteTokenFactory::raw(" - "),
                SQLiteTokenFactory::createFunction('JULIANDAY', [$args[1]]),
            ]);
        case 'DAYNAME':
            return SQLiteTokenFactory::createFunction(
                'STRFTIME',
                ['%w', ...$args]
            );
            case 'DAY':
        case 'DAYOFMONTH':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%d', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'DAYOFWEEK':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%w', ...$args]),
                SQLiteTokenFactory::raw(") + 1 AS INTEGER'"),
            ]);
        case 'DAYOFYEAR':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%j', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'HOUR':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%H', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'MINUTE':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%M', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'MONTH':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%m', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'MONTHNAME':
            return SQLiteTokenFactory::createFunction('STRFTIME', ['%m', ...$args]);
        case 'NOW':
            return new Expression([
                SQLiteTokenFactory::raw("CURRENT_TIMESTAMP()")
            ]);
        case 'SECOND':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%S', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        case 'TIMESTAMP':
            return new Expression([
                SQLiteTokenFactory::raw("DATETIME("),
                ...$args,
                SQLiteTokenFactory::raw(")")
            ]);
        case 'YEAR':
            return new Expression([
                SQLiteTokenFactory::raw("CAST('"),
                SQLiteTokenFactory::createFunction('STRFTIME', ['%Y', ...$args]),
                SQLiteTokenFactory::raw(") AS INTEGER'"),
            ]);
        default:
            throw new Exception('Unsupported function: ' . $name);
    }
}

