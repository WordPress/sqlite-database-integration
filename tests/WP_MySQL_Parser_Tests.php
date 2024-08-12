<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-content/plugins/sqlite-database-integration/wp-includes/mysql-parser/MySQLLexer.php';
require_once __DIR__ . '/../wp-content/plugins/sqlite-database-integration/wp-includes/mysql-parser/MySQLParser.php';

class WP_MySQL_Parser_Tests extends TestCase {

	/**
	 * @dataProvider mysqlQueries
	 */
	public function testParsesWithoutErrors( $query ) {
		$lexer = new MySQLLexer($query, 80019);
		$parser = new MySQLParser($lexer);
		$parser->query();

		// Fake assertion to keep PHPUnit happy and still verify that the query 
		// parsed without fatal errors and exceptions.
		$this->assertEquals(1, 1);
	}

	public static function mysqlQueries() {
		return array(
			'simple' => ['SELECT 1'],
			'simple' => ['SELECT 1'],
			'complexSelect' => [<<<SQL
				WITH mytable AS (select 1 as a, `b`.c from dual)
					SELECT HIGH_PRIORITY DISTINCT
						CONCAT("a", "b"),
						UPPER(z),
						DATE_FORMAT(col_a, '%Y-%m-%d %H:%i:%s') as formatted_date,
						DATE_ADD(col_b, INTERVAL 1 DAY) as date_plus_one,
						col_a
					FROM 
					my_table as subquery
					FORCE INDEX (idx_col_a)
					LEFT JOIN (SELECT a_column_yo from mytable) as t2 
						ON (t2.id = mytable.id AND t2.id = 1)
					WHERE NOT EXISTS (SELECT 1)
					GROUP BY col_a, col_b
					HAVING 1 = 2
					UNION SELECT * from table_cde
					ORDER BY col_a DESC, col_b ASC
					FOR UPDATE
				SQL],
			'createTable' => [<<<SQL
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
				SQL],
			'insertMulti' => [<<<SQL
				INSERT INTO customers (first_name, last_name, email, phone_number, address, birth_date)
					VALUES 
					('John', 'Doe', 'john.doe@example.com', '123-456-7890', JSON_OBJECT('street', '123 Elm St', 'city', 'Springfield', 'state', 'IL', 'zip', '62701'), '1985-05-15'),
					('Jane', 'Smith', 'jane.smith@example.com', '987-654-3210', JSON_OBJECT('street', '456 Oak St', 'city', 'Springfield', 'state', 'IL', 'zip', '62702'), '1990-07-22'),
					('Alice', 'Johnson', 'alice.johnson@example.com', '555-123-4567', JSON_OBJECT('street', '789 Pine St', 'city', 'Springfield', 'state', 'IL', 'zip', '62703'), '1978-11-30');
				SQL],
			'insertSelect' => [<<<SQL
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
				SQL],
			'insertSelectOnDuplicateKey' => [<<<SQL
				INSERT INTO customers (first_name, last_name, email, phone_number, address, birth_date)
					VALUES 
					('Bob', 'Brown', 'bob.brown@example.com', '111-222-3333', JSON_OBJECT('street', '101 Maple St', 'city', 'Springfield', 'state', 'IL', 'zip', '62704'), '1982-03-25')
					ON DUPLICATE KEY UPDATE 
					phone_number = VALUES(phone_number), 
					address = VALUES(address);
				SQL],
			'updateJoin' => [<<<SQL
				UPDATE orders o
					JOIN (
						SELECT customer_id
						FROM orders
						GROUP BY customer_id
						HAVING COUNT(order_id) > 5
					) c ON o.customer_id = c.customer_id
					SET o.`status` = 'Shipped';
				SQL],
			'updateSubQuery' => [<<<SQL
					UPDATE products p
					SET p.stock_quantity = p.stock_quantity - (
						SELECT SUM(o.quantity)
						FROM orders o
						WHERE o.product_id = p.product_id
					)
					WHERE p.product_id = 1;
				SQL],
			'updateJsonSet' => [<<<SQL
				UPDATE customers
					SET address = JSON_SET(address, '$.city', 'New Springfield')
					WHERE email = 'john.doe@example.com';
				SQL],
			'updateCase' => [<<<SQL
					UPDATE products
					SET category = CASE
						WHEN price < 50 THEN 'Books'
						WHEN price BETWEEN 50 AND 200 THEN 'Electronics'
						ELSE 'Home'
					END;
				SQL],
			'updateLimit' => [<<<SQL
					UPDATE orders
					SET `status` = 'Processing'
					WHERE `status` = 'Pending'
					LIMIT 10;
				SQL],
			'deleteJoin' => [<<<SQL
					DELETE o
					FROM orders o
					JOIN customers c ON o.customer_id = c.customer_id
					WHERE c.first_name = 'John';
				SQL],
			'deleteUsing' => [<<<SQL
					DELETE FROM products
					USING products p
					JOIN orders o ON p.product_id = o.product_id
					WHERE o.`status` = 'Cancelled';
				SQL],
			'deleteLimit' => [<<<SQL
					DELETE QUICK LOW_PRIORITY FROM orders
					WHERE `status` = 'Cancelled'
					LIMIT 5;
				SQL],
			'alterConstraint' => [<<<SQL
					ALTER TABLE orders
					ADD CONSTRAINT fk_product_id
					FOREIGN KEY (product_id) REFERENCES products(product_id)
					ON DELETE CASCADE;
				SQL],
			'alterColumn' => ['ALTER TABLE products
					MODIFY COLUMN product_name VARCHAR(200) NOT NULL;'],
			'alterIndex' => ['ALTER TABLE products
					DROP INDEX idx_col_h_i,
					ADD INDEX idx_col_h_i_j (`col_h`, `col_i`, `col_j`);'],
			'dropTable' => ['DROP TABLE products;'],
			'dropIndex' => ['DROP INDEX idx_col_h_i_j ON products;'],
			'dropColumn' => ['ALTER TABLE products DROP COLUMN product_name;'],
			'dropConstraint' => ['ALTER TABLE products DROP FOREIGN KEY fk_product_id;'],
			'dropDatabase' => ['DROP DATABASE mydatabase;'],
			'createDatabase' => ['CREATE DATABASE mydatabase;'],
			'showDatabases' => ['SHOW DATABASES;'],
			'showTables' => ['SHOW TABLES;'],
			'showColumns' => ['SHOW COLUMNS FROM products;'],
			'showIndexes' => ['SHOW INDEXES FROM products;'],
			'showExtendedIndexes' => ['SHOW EXTENDED INDEXES FROM products;'],
			'showConstraints' => ['SHOW CONSTRAINTS FROM products;'],
			'showCreateTable' => ['SHOW CREATE TABLE products;'],
			'showStatus' => ['SHOW STATUS;'],
			'showVariables' => ['SHOW VARIABLES;'],
			'showProcesslist' => ['SHOW PROCESSLIST;'],
			'showGrants' => ['SHOW GRANTS;'],
			'showPrivileges' => ['SHOW PRIVILEGES;'],
			'showEngines' => ['SHOW ENGINES;'],
			'showStorageEngines' => ['SHOW STORAGE ENGINES;'],
			'showPlugins' => ['SHOW PLUGINS;'],
			'showWarnings' => ['SHOW WARNINGS;'],
			'showErrors' => ['SHOW ERRORS;'],
			'showEvents' => ['SHOW EVENTS;'],
			'showTriggers' => ['SHOW TRIGGERS;'],
			'showCreate' => ['SHOW CREATE EVENT myevent;'],
			'showCreateTrigger' => ['SHOW CREATE TRIGGER mytrigger;'],
			'showCreateFunction' => ['SHOW CREATE FUNCTION myfunction;'],
			'showCreateProcedure' => ['SHOW CREATE PROCEDURE myprocedure;'],
			'showCreateView' => ['SHOW CREATE VIEW myview;'],
			'showCreateUser' => ['SHOW CREATE USER myuser;'],
			'showCreateRole' => ['SHOW CREATE ROLE myrole;'],
			'showCreateTablespace' => ['SHOW CREATE TABLESPACE mytablespace;'],
			'showCreateDatabase' => ['SHOW CREATE DATABASE mydatabase;'],
			'showCreateDatabaseIfNotExists' => ['SHOW CREATE DATABASE IF NOT EXISTS myevent;'],
			'showExtended' => ['SHOW EXTENDED COLUMNS FROM products;'],
			'showFull' => ['SHOW FULL COLUMNS FROM products;'],
			'showExtendedFull' => ['SHOW EXTENDED FULL COLUMNS FROM products;'],
			'setVariable' => ['SET @myvar = 1;'],
			'setGlobalVariable' => ['SET GLOBAL myvar = 1;'],
			'setSessionVariable' => ['SET SESSION myvar = 1;'],
			'setTransaction' => ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED;'],
			'setAutocommit' => ['SET AUTOCOMMIT = 0;'],
			'setNames' => ['SET NAMES utf8;'],
			'setCharacter' => ["SET CHARACTER SET utf8;"],
			'setCharacterQuotes' => ["SET CHARACTER SET 'utf8';"],
			'setNamesCollate' => ["SET NAMES 'utf8' COLLATE utf8_general_ci;"],
			'setNamesCollateDefault' => ["SET NAMES 'utf8' COLLATE DEFAULT;"],
			'setSqlMode' => ['SET SQL_MODE = "ANSI_QUOTES";'],
			'setTimeZone' => ['SET TIME_ZONE = "+00:00";'],
			'setPassword' => ["SET PASSWORD = 'newpassword';"],
			'begin' => ['BEGIN;'],
			'commit' => ['COMMIT;'],
			'rollback' => ['ROLLBACK;'],
			'savepoint' => ['SAVEPOINT mysavepoint;'],
			'releaseSavepoint' => ['RELEASE SAVEPOINT mysavepoint;'],
			'rollbackToSavepoint' => ['ROLLBACK TO SAVEPOINT mysavepoint;'],
			'lockTable' => ['LOCK TABLES products WRITE;'],
			'unlockTable' => ['UNLOCK TABLES;'],
			'flush' => ['FLUSH PRIVILEGES;'],
			'flushTables' => ['FLUSH TABLES;'],
			'flushLogs' => ['FLUSH LOGS;'],
			'flushStatus' => ['FLUSH STATUS;'],
			'flushTablesWithReadLock' => ['FLUSH TABLES WITH READ LOCK;'],
			'flushHosts' => ['FLUSH HOSTS;'],
			'flushOptimizerCosts' => ['FLUSH OPTIMIZER_COSTS;'],
		);
	}
}