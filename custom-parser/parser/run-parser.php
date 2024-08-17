<?php

require_once __DIR__ . '/DynamicRecursiveDescentParser.php';
require_once __DIR__ . '/MySQLLexer.php';

$queries = [
    <<<SQL
WITH mytable AS (select 1) SELECT 123 FROM test
SQL,
    <<<SQL
WITH mytable AS (select 1 as a, `b`.c from dual) 
SELECT HIGH_PRIORITY DISTINCT
	CONCAT("a", "b"),
	UPPER(z),
    DATE_FORMAT(col_a, '%Y-%m-%d %H:%i:%s') as formatted_date,
    DATE_ADD(col_b, INTERVAL 1 DAY) as date_plus_one,
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
SQL,
    <<<SQL
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    `description` TEXT,
    category ENUM('Electronics', 'Clothing', 'Books', 'Home', 'Beauty'),
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    product_id INT,
    `status` SET('Pending', 'Shipped', 'Delivered', 'Cancelled'),
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivery_date DATETIME,
    CONSTRAINT fk_customer
        FOREIGN KEY (customer_id) REFERENCES customers (customer_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON DELETE CASCADE,
    INDEX idx_col_h_i (`col_h`, `col_i`),
    INDEX idx_col_g (`col_g`),
    UNIQUE INDEX idx_col_k (`col_k`),
    FULLTEXT INDEX idx_col_l (`col_l`)
) DEFAULT CHARACTER SET cp1250 COLLATE cp1250_general_ci;
SQL,
    <<<SQL
GRANT SELECT ON mytable TO myuser@localhost
SQL,
    <<<SQL
INSERT INTO products
SELECT 
    'Smartphone', 
    'Latest model with advanced features', 
    699.99, 
    50, 
    'Electronics'
WHERE NOT EXISTS (
    SELECT 1 FROM products WHERE product_name = 'Smartphone'
) AND 1=2;
SQL
];

// Assuming MySQLParser.json is in the same directory as this script
$grammar_data = include "./grammar.php";
$grammar = new Grammar($grammar_data);
unset($grammar_data);

// $tokens = tokenizeQuery($queries[0]);
// $parser = new DynamicRecursiveDescentParser($grammar, $tokens);
// $parse_tree = $parser->parse();
// var_dump($parse_tree);
// die();


// foreach ($queries as $k => $query) {
//     $parser = new DynamicRecursiveDescentParser($grammar, tokenizeQuery($query), $lookup);
    // $parser->debug = true;
    // print_r($parse_tree);
//     file_put_contents("query_$k.parsetree", 
//     "QUERY:\n$query\n\nPARSE TREE:\n\n" . json_encode($parse_tree, JSON_PRETTY_PRINT));
// }

// die();
// Benchmark 5 times
echo 'all loaded and deflated'."\n";
$tokens = tokenizeQuery($queries[1]);

var_dump(memory_get_usage(true)/1024/1024);
$start_time = microtime(true);
for ($i = 0; $i < 700; $i++) {
    $parser = new DynamicRecursiveDescentParser($grammar, $tokens);
    $parse_tree = $parser->parse();
}
var_dump(memory_get_usage(true)/1024/1024);
$end_time = microtime(true);
$execution_time = $end_time - $start_time;

// // Output the parse tree
echo json_encode($parse_tree, JSON_PRETTY_PRINT);

// // Output the benchmark result
echo "Execution time: " . $execution_time . " seconds";

