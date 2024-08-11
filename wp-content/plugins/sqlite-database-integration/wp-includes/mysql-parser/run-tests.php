<?php

require __DIR__ . '/MySQLLexer.php';
require __DIR__ . '/MySQLParser.php';

$queries = [
    'simple' => 'SELECT 1',
    'complexSelect' => <<<ACID
WITH mytable AS (select 1 as a, `b`.c from dual)
SELECT HIGH_PRIORITY DISTINCT
	CONCAT("a", "b"),
	UPPER(z),
    DATE_FORMAT(col_a, '%Y-%m-%d %H:%i:%s') as formatted_date,
    DATE_ADD(col_b, INTERVAL 1 DAY) as date_plus_one,
	col_a
FROM 
(SELECT `mycol`, 997482686 FROM "mytable") as subquery
LEFT JOIN (SELECT a_column_yo from mytable) as t2 
    ON (t2.id = mytable.id AND t2.id = 1)
WHERE NOT EXISTS (SELECT 1)
GROUP BY col_a, col_b
HAVING 1 = 2
UNION SELECT * from table_cde
ORDER BY col_a DESC, col_b ASC
FOR UPDATE
ACID,
    'createTable' => <<<ACID
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) CHECK (price > 0),
    stock_quantity INT CHECK (stock_quantity >= 0),
    category ENUM('Electronics', 'Clothing', 'Books', 'Home', 'Beauty'),
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    product_id INT,
    `status` SET('Pending', 'Shipped', 'Delivered', 'Cancelled'),
    quantity INT CHECK (quantity > 0),
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
ACID,
    'insertMulti' => <<<ACID
INSERT INTO customers (first_name, last_name, email, phone_number, address, birth_date)
VALUES 
('John', 'Doe', 'john.doe@example.com', '123-456-7890', JSON_OBJECT('street', '123 Elm St', 'city', 'Springfield', 'state', 'IL', 'zip', '62701'), '1985-05-15'),
('Jane', 'Smith', 'jane.smith@example.com', '987-654-3210', JSON_OBJECT('street', '456 Oak St', 'city', 'Springfield', 'state', 'IL', 'zip', '62702'), '1990-07-22'),
('Alice', 'Johnson', 'alice.johnson@example.com', '555-123-4567', JSON_OBJECT('street', '789 Pine St', 'city', 'Springfield', 'state', 'IL', 'zip', '62703'), '1978-11-30');
ACID,
    'insertSelect' => <<<ACID
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
ACID,
    'insertSelectOnDuplicateKey' => <<<ACID
INSERT INTO customers (first_name, last_name, email, phone_number, address, birth_date)
VALUES 
('Bob', 'Brown', 'bob.brown@example.com', '111-222-3333', JSON_OBJECT('street', '101 Maple St', 'city', 'Springfield', 'state', 'IL', 'zip', '62704'), '1982-03-25')
ON DUPLICATE KEY UPDATE 
phone_number = VALUES(phone_number), 
address = VALUES(address);

ACID
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
    $lexer = new MySQLLexer($query);
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
