<?php

class SQLiteTokenFactory {
    private static $validTypes = [
        SQLiteToken::TYPE_RAW,
        SQLiteToken::TYPE_IDENTIFIER,
        SQLiteToken::TYPE_VALUE,
        SQLiteToken::TYPE_OPERATOR
    ];

    private static $validOperators = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FROM', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER',
        'ON', 'AS', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'GROUP', 'BY', 'ORDER', 'LIMIT', 'OFFSET', 'HAVING',
        'UNION', 'ALL', 'DISTINCT', 'WITH', 'EXISTS', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'LIKE', 'BETWEEN',
        'INTERVAL', 'IF', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'PRAGMA',
        '(', ')', '+', '-', '*', '/', '=', '<>', '!=', '<', '<=', '>', '>=', ';'
    ];

    private static $functions = [
        'ABS' => ['argCount' => 1, 'optionalArgs' => 0],
        'AVG' => ['argCount' => 1, 'optionalArgs' => 0],
        'COUNT' => ['argCount' => 1, 'optionalArgs' => 0],
        'MAX' => ['argCount' => 1, 'optionalArgs' => 0],
        'MIN' => ['argCount' => 1, 'optionalArgs' => 0],
        'ROUND' => ['argCount' => 2, 'optionalArgs' => 1],
        'SUM' => ['argCount' => 1, 'optionalArgs' => 0],
        'LENGTH' => ['argCount' => 1, 'optionalArgs' => 0],
        'UPPER' => ['argCount' => 1, 'optionalArgs' => 0],
        'LOWER' => ['argCount' => 1, 'optionalArgs' => 0],
        'COALESCE' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'SUBSTR' => ['argCount' => 3, 'optionalArgs' => 1],
        'REPLACE' => ['argCount' => 3, 'optionalArgs' => 0],
        'TRIM' => ['argCount' => 3, 'optionalArgs' => 2],
        'DATE' => ['argCount' => 1, 'optionalArgs' => 0],
        'TIME' => ['argCount' => 1, 'optionalArgs' => 0],
        'DATETIME' => ['argCount' => 2, 'optionalArgs' => 1],
        'JULIANDAY' => ['argCount' => 1, 'optionalArgs' => 0],
        'STRFTIME' => ['argCount' => 2, 'optionalArgs' => 0],
        'RANDOM' => ['argCount' => 0, 'optionalArgs' => 0],
        'RANDOMBLOB' => ['argCount' => 1, 'optionalArgs' => 0],
        'NULLIF' => ['argCount' => 2, 'optionalArgs' => 0],
        'IFNULL' => ['argCount' => 2, 'optionalArgs' => 0],
        'INSTR' => ['argCount' => 2, 'optionalArgs' => 0],
        'HEX' => ['argCount' => 1, 'optionalArgs' => 0],
        'QUOTE' => ['argCount' => 1, 'optionalArgs' => 0],
        'LIKE' => ['argCount' => 2, 'optionalArgs' => 1],
        'GLOB' => ['argCount' => 2, 'optionalArgs' => 0],
        'CHAR' => ['argCount' => 1, 'optionalArgs' => PHP_INT_MAX],
        'UNICODE' => ['argCount' => 1, 'optionalArgs' => 0],
        'TOTAL' => ['argCount' => 1, 'optionalArgs' => 0],
        'ZEROBLOB' => ['argCount' => 1, 'optionalArgs' => 0],
        'PRINTF' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'LTRIM' => ['argCount' => 2, 'optionalArgs' => 1],
        'RTRIM' => ['argCount' => 2, 'optionalArgs' => 1],
        'BLOB' => ['argCount' => 1, 'optionalArgs' => 0],
        'GROUP_CONCAT' => ['argCount' => 1, 'optionalArgs' => 1],
        'JSON' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_ARRAY' => ['argCount' => 1, 'optionalArgs' => PHP_INT_MAX],
        'JSON_OBJECT' => ['argCount' => 1, 'optionalArgs' => PHP_INT_MAX],
        'JSON_QUOTE' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_VALID' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_ARRAY_LENGTH' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_EXTRACT' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'JSON_INSERT' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'JSON_REPLACE' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'JSON_SET' => ['argCount' => 2, 'optionalArgs' => PHP_INT_MAX],
        'JSON_PATCH' => ['argCount' => 2, 'optionalArgs' => 0],
        'JSON_REMOVE' => ['argCount' => 1, 'optionalArgs' => PHP_INT_MAX],
        'JSON_TYPE' => ['argCount' => 1, 'optionalArgs' => PHP_INT_MAX],
        'JSON_DEPTH' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_KEYS' => ['argCount' => 1, 'optionalArgs' => 1],
        'JSON_GROUP_ARRAY' => ['argCount' => 1, 'optionalArgs' => 0],
        'JSON_GROUP_OBJECT' => ['argCount' => 1, 'optionalArgs' => 0],
    ];

    public static function registerFunction(string $name, int $argCount, int $optionalArgs = 0): void {
        self::$functions[strtoupper($name)] = [
            'argCount' => $argCount,
            'optionalArgs' => $optionalArgs
        ];
    }

    public static function raw(string $value): SQLiteToken {
        return self::create(SQLiteToken::TYPE_RAW, $value);
    }

    public static function identifier(string $value): SQLiteToken {
        return self::create(SQLiteToken::TYPE_IDENTIFIER, $value);
    }

    public static function value($value): SQLiteToken {
        return self::create(SQLiteToken::TYPE_VALUE, self::escapeValue($value));
    }

    public static function doubleQuotedValue($value): SQLiteToken {
        $value = substr($value, 1, -1);
        $value = str_replace('\"', '"', $value);
        $value = str_replace('""', '"', $value);
        return self::create(SQLiteToken::TYPE_VALUE, self::escapeValue($value));
    }

    public static function operator(string $value): SQLiteToken {
        $upperValue = strtoupper($value);
        if (!in_array($upperValue, self::$validOperators, true)) {
            throw new InvalidArgumentException("Invalid SQLite operator or keyword: $value");
        }
        return self::create(SQLiteToken::TYPE_OPERATOR, $upperValue);
    }

    public static function createFunction(string $name, array $expressions): Expression {
        $upperName = strtoupper($name);
        if (!isset(self::$functions[$upperName])) {
            throw new InvalidArgumentException("Unknown SQLite function: $name");
        }

        $functionSpec = self::$functions[$upperName];
        $minArgs = $functionSpec['argCount'] - $functionSpec['optionalArgs'];
        $maxArgs = $functionSpec['argCount'];

        if (count($expressions) < $minArgs || count($expressions) > $maxArgs) {
            throw new InvalidArgumentException(
                "Function $name expects between $minArgs and $maxArgs arguments, " .
                count($expressions) . " given."
            );
        }

        $tokens = [];
        $tokens[] = self::raw($upperName);
        $tokens[] = self::raw('(');

        foreach ($expressions as $index => $expression) {
            if ($index > 0) {
                $tokens[] = self::raw(',');
            }
            if (!$expression instanceof Expression) {
                throw new InvalidArgumentException("All arguments must be instances of Expression");
            }
            $tokens = array_merge($tokens, $expression->elements);
        }

        $tokens[] = self::raw(')');

        return new SQLiteExpression($tokens);
    }

    private static function create(string $type, string $value): SQLiteToken {
        if (!in_array($type, self::$validTypes, true)) {
            throw new InvalidArgumentException("Invalid token type: $type");
        }
        return new SQLiteToken($type, $value);
    }

    private static function escapeValue($value): string {
        if (is_string($value)) {
            // Ensure the string is valid UTF-8, replace invalid characters with an empty string
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            // Escape single quotes by doubling them
            $value = str_replace("'", "''", $value);

            // Escape backslashes by doubling them
            $value = str_replace("\\", "\\\\", $value);

            // Remove null characters
            $value = str_replace("\0", "", $value);

            // Return the escaped string enclosed in single quotes
            return "'" . $value . "'";
        } elseif (is_int($value) || is_float($value)) {
            return (string)$value;
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_null($value)) {
            return 'NULL';
        } else {
            throw new InvalidArgumentException("Unsupported value type: " . gettype($value));
        }
    }
}


class SQLiteToken
{
    const TYPE_RAW = 'TYPE_RAW';
    const TYPE_IDENTIFIER = 'TYPE_IDENTIFIER';
    const TYPE_VALUE = 'TYPE_VALUE';
    const TYPE_OPERATOR = 'TYPE_OPERATO';

    public $type;
    public $value;

    public function __construct(string $type, $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

}

class SQLiteQueryBuilder {
    private Expression $expression;

    static public function stringify(Expression $expression){
        return (new SQLiteQueryBuilder($expression))->buildQuery();
    }

    public function __construct(Expression $expression) {
        $this->expression = $expression;
    }

    public function buildQuery(): string {
        $queryParts = [];
        foreach ($this->expression->getTokens() as $element) {
            if ($element instanceof SQLiteToken) {
                $queryParts[] = $this->processToken($element);
            } elseif ($element instanceof Expression) {
                $queryParts[] = '(' . (new self($element))->buildQuery() . ')';
            }
        }
        return implode(' ', $queryParts);
    }

    private function processToken(SQLiteToken $token): string {
        switch ($token->type) {
            case SQLiteToken::TYPE_RAW:
            case SQLiteToken::TYPE_OPERATOR:
                return $token->value;
            case SQLiteToken::TYPE_IDENTIFIER:
                return '"' . str_replace('"', '""', $token->value) . '"';
            case SQLiteToken::TYPE_VALUE:
                return $token->value;
            default:
                throw new InvalidArgumentException("Unknown token type: " . $token->type);
        }
    }
}

class Expression
{
    public $elements;

    public function __construct(array $elements = [])
    {
        $new_elements = [];
        $elements = array_filter($elements, fn($x) => $x);
        foreach($elements as $element) {
            if(is_object($element) && $element instanceof Expression) {
                $new_elements = array_merge($new_elements, $element->elements);
            } else {
                $new_elements[] = $element;
            }
        }
        $this->elements = $new_elements;
    }

    public function getTokens()
    {
        return $this->elements;        
    }

    public function addToken(SQLiteToken $token)
    {
        $this->elements[] = $token;
    }

    public function addTokens(array $tokens)
    {
        foreach ($tokens as $token) {
            $this->addToken($token);
        }
    }

    public function addExpression($expression)
    {
        $this->addToken($expression);
    }

}

class SQLiteExpression extends Expression {}

class MySQLToSQLiteDriver
{
    private $pdo;

    public function __construct($dsn, $username = null, $password = null, $options = [])
    {
        $this->pdo = new PDO($dsn, $username, $password, $options);
    }

    public function query(array $mysqlAst)
    {
        $transformer = new SQLTransformer($mysqlAst, 'sqlite');
        $expression = $transformer->transform();
        if ($expression !== null) {
            $queryString = (string)$expression;
            return $this->pdo->query($queryString);
        } else {
            throw new Exception('Failed to transform query.');
        }
    }
}

// Example usage:

// Sample parsed MySQL AST (Abstract Syntax Tree)
// $ast = [
//     'type' => 'select',
//     'columns' => [
//         ['name' => '*', 'type' => 'ALL'],
//         ['name' => 'created_at', 'type' => 'DATETIME']
//     ],
//     'from' => 'users',
//     'keywords' => ['SELECT', 'FROM'],
//     'options' => ['DISTINCT']
// ];

// try {
//     $driver = new MySQLToSQLiteDriver('sqlite::memory:');
//     $result = $driver->query($ast);
//     foreach ($result as $row) {
//         print_r($row);
//     }
// } catch (Exception $e) {
//     echo $e->getMessage();
// }
