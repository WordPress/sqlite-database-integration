<?php

require_once __DIR__ . '/WP_DuckDB_Differential_TestCase.php';

/**
 * Differential tests that compare supported DuckDB behavior against SQLite.
 *
 * @group duckdb
 * @group duckdb-parity
 */
class WP_DuckDB_Driver_Parity_Tests extends WP_DuckDB_Differential_TestCase {
	public function test_select_literals_and_aliases_match_sqlite(): void {
		$this->assertParityRows( "SELECT 1 AS id, 'Ada' AS name" );
	}

	public function test_common_scalar_functions_match_sqlite(): void {
		$this->assertParityRows( "SELECT GREATEST('a', 'b') AS greatest_value, LEAST('a', 'b') AS least_value" );
		$this->assertParityRows( "SELECT SUBSTR('abcdef', 2, 3) AS short_substr, SUBSTRING('abcdef', 2, 3) AS long_substring" );
	}

	public function test_select_date_time_literal_functions_match_sqlite(): void {
		foreach (
			array(
				'SELECT LENGTH(NOW()) AS value_length, SUBSTR(NOW(), 5, 1) AS date_sep, SUBSTR(NOW(), 14, 1) AS time_sep',
				'SELECT LENGTH(CURRENT_TIMESTAMP()) AS value_length, SUBSTR(CURRENT_TIMESTAMP(), 5, 1) AS date_sep, SUBSTR(CURRENT_TIMESTAMP(), 14, 1) AS time_sep',
				'SELECT LENGTH(CURDATE()) AS value_length, SUBSTR(CURDATE(), 5, 1) AS date_sep',
				'SELECT LENGTH(UTC_DATE()) AS value_length, SUBSTR(UTC_DATE(), 5, 1) AS date_sep',
				'SELECT LENGTH(UTC_TIME()) AS value_length, SUBSTR(UTC_TIME(), 3, 1) AS hour_sep',
				'SELECT LENGTH(UTC_TIMESTAMP()) AS value_length, SUBSTR(UTC_TIMESTAMP(), 5, 1) AS date_sep, SUBSTR(UTC_TIMESTAMP(), 14, 1) AS time_sep',
				"SELECT DATE('2008-01-02 13:29:17') AS value_date",
				"SELECT DATE('not-a-date') AS value_date",
				"SELECT DATEDIFF('2008-01-09 13:29:17', '2008-01-02 00:00:00') AS day_delta",
				"SELECT DATE_FORMAT('not-a-date', '%Y-%m-%d') AS formatted",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 SECOND) AS shifted",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 2 WEEK) AS shifted",
				"SELECT DATE_SUB('2008-01-02 13:29:17', INTERVAL 1 MONTH) AS shifted",
				"SELECT DATE_ADD('not-a-date', INTERVAL 1 DAY) AS shifted",
				"SELECT DATE_SUB('not-a-date', INTERVAL 1 DAY) AS shifted",
				"SELECT DATE(DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 DAY)) AS nested_date",
				"SELECT DATE_ADD('2008-01-02 13:29:17', INTERVAL 1 HOUR) AS shifted ORDER BY shifted",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_datediff_invalid_string_inputs_match_sqlite(): void {
		foreach (
			array(
				"SELECT DATEDIFF('not-a-date', '2020-01-01') AS day_delta",
				"SELECT DATEDIFF('2020-01-01', 'not-a-date') AS day_delta",
				"SELECT DATEDIFF('bad-a', 'bad-b') AS day_delta",
			) as $sql
		) {
			$this->assertParityErrorContains( $sql, 'Failed to parse time string' );
		}
	}

	public function test_date_format_numeric_literal_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_format_posts (ID BIGINT, post_date_gmt DATETIME)',
				"INSERT INTO wp_date_format_posts (ID, post_date_gmt) VALUES
					(1, '2016-01-16 00:00:00'),
					(2, '2016-01-17 00:00:00'),
					(3, NULL)",
			)
		);

		foreach (
			array(
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') = 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') != 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') > 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') >= 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') < 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') <= 20160116 ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 = DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 != DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 > DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 >= DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 < DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE 20160116 <= DATE_FORMAT(post_date_gmt, '%Y%m%d') ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%Y%m%d') = '20160116' ORDER BY ID",
				"SELECT ID FROM wp_date_format_posts WHERE DATE_FORMAT(post_date_gmt, '%H.%i') >= 0.00 ORDER BY ID",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_date_function_numeric_literal_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_function_posts (ID BIGINT, post_date_gmt DATETIME)',
				"INSERT INTO wp_date_function_posts (ID, post_date_gmt) VALUES
					(1, '2016-01-16 00:00:00'),
					(2, '2016-01-17 00:00:00'),
					(3, NULL)",
			)
		);

		foreach (
			array(
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) = 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) != 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) > 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) >= 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) < 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) <= 20160116 ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 = DATE(post_date_gmt) ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 != DATE(post_date_gmt) ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 > DATE(post_date_gmt) ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 >= DATE(post_date_gmt) ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 < DATE(post_date_gmt) ORDER BY ID',
				'SELECT ID FROM wp_date_function_posts WHERE 20160116 <= DATE(post_date_gmt) ORDER BY ID',
				"SELECT ID FROM wp_date_function_posts WHERE DATE(post_date_gmt) = '2016-01-16' ORDER BY ID",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_date_add_sub_numeric_literal_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_add_sub_posts (ID BIGINT, post_date_gmt DATETIME)',
				"INSERT INTO wp_date_add_sub_posts (ID, post_date_gmt) VALUES
					(1, '2016-01-16 00:00:00'),
					(2, '2016-01-17 12:30:00'),
					(3, NULL),
					(4, '2017-02-03 04:05:06')",
			)
		);

		foreach (
			array(
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) = 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) != 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) > 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) >= 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) < 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) <= 20160117 ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE 20160117 = DATE_ADD(post_date_gmt, INTERVAL 1 DAY) ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE 20160117 != DATE_ADD(post_date_gmt, INTERVAL 1 DAY) ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE 20160117 < DATE_SUB(post_date_gmt, INTERVAL 1 DAY) ORDER BY ID',
				'SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 HOUR) <= 20160117133000 ORDER BY ID',
				"SELECT ID FROM wp_date_add_sub_posts WHERE DATE_ADD(post_date_gmt, INTERVAL 1 DAY) = '2016-01-17 00:00:00' ORDER BY ID",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_date_add_sub_numeric_literal_comparisons_preserve_expression_errors(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_add_sub_error_posts (ID BIGINT)',
			)
		);

		$this->assertParityErrorContains(
			'SELECT ID FROM wp_date_add_sub_error_posts WHERE DATE_ADD(missing_col, INTERVAL 1 DAY) = 20160117 ORDER BY ID',
			'missing_col'
		);
		$this->assertParityErrorContains(
			'SELECT ID FROM wp_date_add_sub_error_posts WHERE 20160117 < DATE_SUB(missing_col, INTERVAL 1 DAY) ORDER BY ID',
			'missing_col'
		);
	}

	public function test_date_part_quoted_string_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_part_posts (ID BIGINT, post_date_gmt DATETIME, post_date DATETIME)',
				"INSERT INTO wp_date_part_posts (ID, post_date_gmt, post_date) VALUES
					(1, '2016-01-16 00:00:00', '2016-01-16 13:14:15'),
					(2, '2016-01-17 12:30:00', '2016-01-17 00:00:00'),
					(3, '2015-12-31 23:59:59', '2015-12-31 23:59:59'),
					(4, '2017-02-03 04:05:06', '2017-02-03 04:05:06')",
			)
		);

		foreach (
			array(
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) = '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) != '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) > '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) >= '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) < '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) <= '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' = YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' != YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' > YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' >= YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' < YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE '2016' <= YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE MONTH(post_date_gmt) = '1' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE DAYOFMONTH(post_date_gmt) > '16' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE DAYOFWEEK(post_date_gmt) != '7' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE WEEK(post_date_gmt, 1) = '2' ORDER BY ID",
				"SELECT ID FROM wp_date_part_posts WHERE HOUR(post_date) = '13' ORDER BY ID",
				'SELECT ID FROM wp_date_part_posts WHERE YEAR(post_date_gmt) = 2016 ORDER BY ID',
				'SELECT ID FROM wp_date_part_posts WHERE MONTH(post_date_gmt) = 1 ORDER BY ID',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_date_part_quoted_string_comparisons_match_sqlite_for_invalid_strings(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_part_invalid_posts (ID BIGINT, post_date_gmt VARCHAR(255), post_date VARCHAR(255))',
				"INSERT INTO wp_date_part_invalid_posts (ID, post_date_gmt, post_date) VALUES
					(1, 'not-a-date', 'not-a-time'),
					(2, '2016-01-16 00:00:00', '2016-01-16 13:14:15')",
			)
		);

		foreach (
			array(
				"SELECT ID FROM wp_date_part_invalid_posts WHERE YEAR(post_date_gmt) = '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_invalid_posts WHERE YEAR(post_date_gmt) != '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_invalid_posts WHERE YEAR(post_date_gmt) < '2016' ORDER BY ID",
				"SELECT ID FROM wp_date_part_invalid_posts WHERE '2016' > YEAR(post_date_gmt) ORDER BY ID",
				"SELECT ID FROM wp_date_part_invalid_posts WHERE HOUR(post_date) = '13' ORDER BY ID",
				"SELECT ID FROM wp_date_part_invalid_posts WHERE HOUR(post_date) != '13' ORDER BY ID",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_date_part_quoted_string_comparisons_preserve_expression_errors(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_date_part_error_posts (ID BIGINT)',
			)
		);

		$this->assertParityErrorContains(
			"SELECT ID FROM wp_date_part_error_posts WHERE YEAR(missing_col) = '2016' ORDER BY ID",
			'missing_col'
		);
		$this->assertParityErrorContains(
			"SELECT ID FROM wp_date_part_error_posts WHERE '2016' != YEAR(missing_col) ORDER BY ID",
			'missing_col'
		);
	}

	public function test_select_unseeded_rand_range_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT CAST(RAND() >= 0 AND RAND() < 1 AS SIGNED) AS rand_in_range' );
	}

	public function test_select_seeded_rand_literals_match_sqlite(): void {
		foreach (
			array(
				'SELECT RAND(0) AS r',
				'SELECT RAND(1) AS r',
				'SELECT RAND(5) AS r',
				'SELECT RAND(NULL) AS r',
				"SELECT RAND('5') AS r",
				"SELECT RAND('3.9') AS r",
				'SELECT RAND(3.9) AS r',
				'SELECT RAND(-1) AS r',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_select_seeded_rand_multi_row_sequence_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_rows (id INT)',
				'INSERT INTO seeded_rand_rows (id) VALUES (1), (2), (3)',
			)
		);

		$this->assertParityRows( 'SELECT id, RAND(3) AS r FROM seeded_rand_rows ORDER BY id' );
	}

	public function test_select_seeded_rand_expression_seeds_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_expr (id INT, seed_text VARCHAR(20))',
				"INSERT INTO seeded_rand_expr (id, seed_text) VALUES (1, '1'), (2, '2'), (3, '3')",
			)
		);

		$this->assertParityRows( 'SELECT id, RAND(CAST(seed_text AS SIGNED)) AS r FROM seeded_rand_expr ORDER BY id' );
		$this->assertParityRows( 'SELECT RAND(NULLIF(1, 1)) AS r' );
		$this->assertParityRows( 'SELECT RAND(CAST(1 AS SIGNED))' );
	}

	public function test_select_wildcard_seeded_rand_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_wildcard (id INT, name VARCHAR(20))',
				"INSERT INTO seeded_rand_wildcard (id, name) VALUES (1, 'a'), (2, 'b')",
			)
		);

		$this->assertParityRows( 'SELECT *, RAND(1) AS r FROM seeded_rand_wildcard ORDER BY id' );
		$this->assertParityRows( 'SELECT *, RAND(1) AS r, id AS explicit_id FROM seeded_rand_wildcard ORDER BY id' );
		$this->assertParityRows( 'SELECT *, id AS explicit_id, RAND(CAST(id AS SIGNED)) AS r FROM seeded_rand_wildcard ORDER BY id' );
	}

	public function test_insert_values_seeded_rand_literals_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_out (id INT, value DOUBLE, other DOUBLE)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO seeded_rand_out (id, value, other) VALUES (1, RAND(1), RAND(1)), (2, RAND(1), RAND(1))' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_out ORDER BY id' );

		$this->assertParityRowCount( 'INSERT INTO seeded_rand_out (id, value, other) VALUES (3, RAND(1), RAND(NULL))' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_out ORDER BY id' );

		$this->assertParityRowCount( 'INSERT INTO seeded_rand_out (id, value, other) VALUES (4, RAND(1) + 0, 0 + RAND(1))' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_out ORDER BY id' );
	}

	public function test_insert_set_seeded_rand_literals_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_set (id INT, value DOUBLE, other DOUBLE)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO seeded_rand_set SET id = 1, value = RAND(1), other = RAND(1)' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_set ORDER BY id' );

		$this->assertParityRowCount( 'INSERT seeded_rand_set SET id = 2, value = RAND(NULL), other = RAND(1) + 0' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_set ORDER BY id' );
	}

	public function test_update_seeded_rand_literals_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_update (id INT, value DOUBLE, other DOUBLE)',
				'INSERT INTO seeded_rand_update (id, value, other) VALUES (1, 0.0, 0.0), (2, 0.0, 0.0), (3, 0.0, 0.0)',
			)
		);

		$this->assertParityRowCount( 'UPDATE seeded_rand_update SET value = RAND(1) WHERE id = 1' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_update ORDER BY id' );

		$this->assertParityRowCount( 'UPDATE seeded_rand_update SET value = RAND(1), other = RAND(1) ORDER BY id LIMIT 2' );
		$this->assertParityRows( 'SELECT id, value, other FROM seeded_rand_update ORDER BY id' );
	}

	public function test_select_order_by_seeded_rand_literal_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_order (id INT)',
				'INSERT INTO seeded_rand_order (id) VALUES (1), (2), (3), (4), (5)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1)' );
		$this->assertParityRows( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1) DESC' );
		$this->assertParityRows( 'SELECT id FROM seeded_rand_order ORDER BY RAND(1) DESC LIMIT 1, 2' );
	}

	public function test_seeded_rand_select_and_update_where_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_rand_ctx (id INT, value DOUBLE)',
				'INSERT INTO seeded_rand_ctx (id, value) VALUES (3, 0.0), (1, 0.0), (2, 0.0)',
				'CREATE TABLE seeded_rand_delete_ctx (id INT)',
				'INSERT INTO seeded_rand_delete_ctx (id) VALUES (3), (1), (2)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM seeded_rand_ctx WHERE RAND(1) < 0.5 ORDER BY id' );
		$this->assertParityRowCount( 'UPDATE seeded_rand_ctx SET value = 9 WHERE RAND(1) < 0.5' );
		$this->assertParityRows( 'SELECT id, value FROM seeded_rand_ctx ORDER BY id' );
		$this->assertParityRowCount( 'DELETE FROM seeded_rand_delete_ctx WHERE RAND(1) < 0.5' );
		$this->assertParityRows( 'SELECT id FROM seeded_rand_delete_ctx ORDER BY id' );
	}

	public function test_select_cast_convert_binary_expressions_match_sqlite(): void {
		foreach (
			array(
				"SELECT CAST('42' AS SIGNED) AS v",
				"SELECT CAST('-10' AS SIGNED) AS v",
				"SELECT CAST('3.5' AS DECIMAL(10,2)) AS v",
				'SELECT CAST(123 AS CHAR) AS v',
				"SELECT CAST('abc' AS BINARY) = 'abc' AS v",
				"SELECT CONVERT('abc', BINARY) = 'abc' AS v",
				"SELECT CONVERT('abc', CHAR) AS v",
				"SELECT CONVERT('-10', SIGNED) AS v",
				"SELECT CONVERT('Customer' USING utf8mb4) AS v",
				"SELECT CONVERT('Customer' USING utf8mb4) COLLATE utf8mb4_bin AS v",
				"SELECT BINARY 'abc' = 'abc' AS v",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_select_like_binary_expressions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE expr_strings (name VARCHAR(20))',
				"INSERT INTO expr_strings (name) VALUES ('abc'), ('ABC'), ('a_c'), ('a%c'), ('ábC')",
			)
		);

		foreach (
			array(
				"SELECT name FROM expr_strings WHERE BINARY name = 'ABC' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'A%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name NOT LIKE BINARY 'A%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'a\\_%' ORDER BY name",
				"SELECT name FROM expr_strings WHERE name LIKE BINARY 'a\\%%' ORDER BY name",
				'SELECT name FROM expr_strings ORDER BY BINARY name',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_create_insert_select_update_delete_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1), (2, 'second', 2)",
			)
		);

		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "UPDATE items SET hits = 3 WHERE name = 'old'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "DELETE FROM items WHERE name = 'second'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_case_only_update_on_case_insensitive_column_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_users (
					ID bigint(20) unsigned NOT NULL auto_increment,
					user_login varchar(60) NOT NULL default '',
					user_email varchar(100) NOT NULL default '',
					PRIMARY KEY (ID),
					KEY user_email (user_email)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_users (ID, user_login, user_email) VALUES (1, 'editor', 'editor@example.com')",
			)
		);

		$this->assertParityRowCount( "UPDATE wp_users SET user_email = 'Editor@example.com' WHERE ID = 1" );
		$this->assertParityRows( 'SELECT ID, user_email FROM wp_users ORDER BY ID' );
		$this->runParitySetup( array( "UPDATE wp_users SET user_email = 'Editor@example.com' WHERE ID = 1" ) );
		$this->assertParityRows( 'SELECT ID, user_email FROM wp_users ORDER BY ID' );
	}

	public function test_attachment_mime_distinct_no_order_matches_sqlite_first_seen_order(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_posts (ID BIGINT PRIMARY KEY, post_type VARCHAR(20), post_mime_type VARCHAR(100))',
				"INSERT INTO wp_posts (ID, post_type, post_mime_type) VALUES
					(10, 'attachment', 'image/jpeg'),
					(11, 'attachment', 'image/jpeg'),
					(12, 'attachment', 'application/pdf'),
					(13, 'post', 'text/plain'),
					(14, 'attachment', '')",
			)
		);

		$this->assertParityRows( "SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment'" );
		$this->assertParityRows( "SELECT DISTINCT `post_mime_type` FROM `wp_posts` WHERE `post_type` = 'attachment'" );
		$this->assertParityRows( "SELECT DISTINCT post_mime_type FROM wp_posts WHERE post_type = 'attachment' AND post_mime_type != ''" );
		$this->assertParityRows( "SELECT DISTINCT `post_mime_type` FROM `wp_posts` WHERE `post_mime_type` <> '' AND `post_type` = 'attachment'" );
	}

	public function test_posts_date_order_ties_match_sqlite_index_order(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_modified DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_title VARCHAR(200) NOT NULL DEFAULT '',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID),
					KEY type_status_date (post_type, post_status, post_date, ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_posts (ID, post_date, post_modified, post_title, post_type, post_status) VALUES
					(1, '2024-01-01 00:00:00', '2024-02-01 00:00:00', 'same', 'post', 'publish'),
					(2, '2024-01-01 00:00:00', '2024-02-01 00:00:00', 'same', 'post', 'publish'),
					(3, '2024-01-01 00:00:00', '2024-02-01 00:00:00', 'same', 'post', 'publish'),
					(4, '2024-01-02 00:00:00', '2024-02-02 00:00:00', 'same', 'post', 'publish'),
					(5, '2024-01-03 00:00:00', '2024-02-03 00:00:00', 'same', 'post', 'draft')",
			)
		);

		$this->assertParityRows(
			"SELECT ID FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			ORDER BY post_date DESC"
		);
		$this->assertParityRows(
			"SELECT p.ID FROM wptests_posts AS p
			WHERE p.post_type = 'post' AND p.post_status = 'publish'
			ORDER BY p.post_modified ASC"
		);
		$this->assertParityRows(
			"SELECT ID FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			ORDER BY post_modified DESC"
		);
		$this->assertParityRows(
			"SELECT ID FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			ORDER BY post_date DESC, post_title ASC"
		);
	}

	public function test_posts_page_menu_title_order_ties_match_sqlite_index_order(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					post_title VARCHAR(200) NOT NULL DEFAULT '',
					post_excerpt TEXT NOT NULL,
					post_content TEXT NOT NULL,
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					menu_order INT(11) NOT NULL DEFAULT '0',
					PRIMARY KEY (ID),
					KEY post_parent (post_parent)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_posts (ID, post_parent, post_title, post_excerpt, post_content, post_type, menu_order) VALUES
					(145, 0, 'Top Level Page 1', '', '', 'page', 0),
					(146, 0, 'Top Level Page 2', '', '', 'page', 0),
					(147, 0, 'Top Level Page 3', '', '', 'page', 0),
					(148, 0, 'Top Level Page 4', '', '', 'page', 0),
					(149, 0, 'Top Level Page 5', '', '', 'page', 0),
					(150, 145, 'Child 1', '', '', 'page', 0),
					(151, 145, 'Child 2', '', '', 'page', 0),
					(152, 145, 'Child 3', '', '', 'page', 0),
					(153, 146, 'Child 1', '', '', 'page', 0),
					(154, 146, 'Child 2', '', '', 'page', 0),
					(155, 146, 'Child 3', '', '', 'page', 0),
					(156, 147, 'Child 1', '', '', 'page', 0),
					(157, 147, 'Child 2', '', '', 'page', 0),
					(158, 147, 'Child 3', '', '', 'page', 0),
					(159, 148, 'Child 1', '', '', 'page', 0),
					(160, 148, 'Child 2', '', '', 'page', 0),
					(161, 148, 'Child 3', '', '', 'page', 0),
					(162, 149, 'Child 1', '', '', 'page', 0),
					(163, 149, 'Child 2', '', '', 'page', 0),
					(164, 149, 'Child 3', '', '', 'page', 0),
					(165, 156, 'Child Grand 1', '', '', 'page', 0),
					(166, 157, 'Child Grand 2', '', '', 'page', 0),
					(167, 158, 'Child Grand 3', '', '', 'page', 0),
					(168, 161, 'Child Grand 4', '', '', 'page', 0)",
			)
		);

		$this->assertParityRows(
			"SELECT ID, post_parent, post_title
			FROM wptests_posts
			WHERE post_type = 'page'
				AND (post_title LIKE '%Child%' OR post_excerpt LIKE '%Child%' OR post_content LIKE '%Child%')
			ORDER BY menu_order ASC, post_title ASC"
		);
	}

	public function test_rest_posts_tags_exclude_grouped_order_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					PRIMARY KEY (ID),
					KEY type_status_date (post_type, post_status, post_date, ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"CREATE TABLE wptests_term_relationships (
					object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					term_order INT(11) NOT NULL DEFAULT '0',
					PRIMARY KEY (object_id, term_taxonomy_id),
					KEY term_taxonomy_id (term_taxonomy_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_posts (ID, post_date, post_status, post_type) VALUES
					(4071, '2026-06-29 09:00:00', 'publish', 'post'),
					(4072, '2026-06-29 09:00:00', 'publish', 'post'),
					(4073, '2026-06-29 09:00:00', 'publish', 'post'),
					(4074, '2026-06-29 09:00:00', 'publish', 'post')",
				'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
					(4071, 99, 0)',
			)
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE 1=1
				AND ( wptests_posts.ID NOT IN (
					SELECT object_id
					FROM wptests_term_relationships
					WHERE term_taxonomy_id IN (99)
				) )
				AND ((wptests_posts.post_type = 'post' AND (wptests_posts.post_status = 'publish')))
			GROUP BY wptests_posts.ID
			ORDER BY wptests_posts.post_date DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_rest_iso_datetime_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_posts (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_modified_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_posts (ID, post_date_gmt, post_modified_gmt, post_type, post_status) VALUES
					(1, '2020-01-01 00:00:00', '2020-02-01 00:00:00', 'post', 'publish'),
					(2, '2020-01-02 12:00:00', '2020-02-02 12:00:00', 'post', 'publish'),
					(3, '2020-01-03 00:00:00', '2020-02-03 00:00:00', 'post', 'publish'),
					(4, '2020-01-02 12:00:00', '2020-02-02 12:00:00', 'page', 'publish'),
					(5, '2020-01-02 12:00:00', '2020-02-02 12:00:00', 'attachment', 'inherit'),
					(6, '2020-01-03 00:00:00', '2020-02-03 00:00:00', 'page', 'publish')",
				"CREATE TABLE wp_comments (
					comment_ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					comment_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
					PRIMARY KEY (comment_ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_comments (comment_ID, comment_date_gmt, comment_approved) VALUES
					(11, '2020-01-01 00:00:00', '1'),
					(12, '2020-01-02 12:00:00', '1'),
					(13, '2020-01-03 00:00:00', '1')",
				'CREATE TABLE wp_rest_strings (
					id INT NOT NULL,
					value VARCHAR(100) NOT NULL,
					PRIMARY KEY (id)
				)',
				"INSERT INTO wp_rest_strings (id, value) VALUES
					(1, '2020-01-02T00:00:00Z'),
					(2, 'not-a-date')",
			)
		);

		$this->assertParityRows(
			"SELECT ID FROM wp_posts
			WHERE post_date_gmt >= '2020-01-02T00:00:00Z'
				AND post_date_gmt <= '2020-01-02T23:59:59Z'
				AND post_type = 'post'
			ORDER BY ID"
		);
		$this->assertParityRows(
			"SELECT ID FROM wp_posts
			WHERE post_date_gmt >= '2020-01-02T00:00:00Z'
				AND post_date_gmt <= '2020-01-02T23:59:59Z'
				AND post_type = 'page'
			ORDER BY ID"
		);
		$this->assertParityRows(
			"SELECT ID FROM wp_posts
			WHERE post_date_gmt >= '2020-01-02T00:00:00Z'
				AND post_date_gmt <= '2020-01-02T23:59:59Z'
				AND post_type = 'attachment'
			ORDER BY ID"
		);
		$this->assertParityRows(
			"SELECT ID FROM wp_posts
			WHERE post_modified_gmt >= '2020-02-02T00:00:00Z'
				AND post_modified_gmt <= '2020-02-02T23:59:59Z'
			ORDER BY ID"
		);
		$this->assertParityRows(
			"SELECT comment_ID FROM wp_comments
			WHERE comment_date_gmt >= '2020-01-02T00:00:00Z'
				AND comment_date_gmt <= '2020-01-02T23:59:59Z'
			ORDER BY comment_ID"
		);
		$this->assertParityRows(
			"SELECT comment_ID FROM wp_comments
			WHERE '2020-01-02T00:00:00Z' <= comment_date_gmt
				AND '2020-01-02T23:59:59Z' >= comment_date_gmt
			ORDER BY comment_ID"
		);
		$this->assertParityRows(
			"SELECT ID FROM wp_posts
			WHERE post_date_gmt >= '2020-01-02 00:00:00'
				AND post_date_gmt <= '2020-01-02 23:59:59'
			ORDER BY ID"
		);
		$this->assertParityRows( "SELECT id FROM wp_rest_strings WHERE value = '2020-01-02T00:00:00Z' ORDER BY id" );
		$this->assertParityRows( "SELECT ID FROM wp_posts WHERE post_type = '2020-01-02T00:00:00Z' ORDER BY ID" );
	}

	public function test_rest_date_query_sql_calc_found_rows_shapes_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_posts (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID),
					KEY type_status_date (post_type, post_status, post_date, ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_posts (ID, post_date, post_date_gmt, post_type, post_status) VALUES
					(1, '2020-01-01 00:00:00', '2020-01-01 00:00:00', 'post', 'publish'),
					(2, '2020-01-02 12:00:00', '2020-01-02 12:00:00', 'post', 'publish'),
					(3, '2020-01-03 00:00:00', '2020-01-03 00:00:00', 'post', 'publish'),
					(4, '2020-01-02 11:00:00', '2020-01-02 12:00:00', 'page', 'publish'),
					(5, '2020-01-02 10:00:00', '2020-01-02 12:00:00', 'attachment', 'inherit'),
					(6, '2020-01-02 09:00:00', '2020-01-02 12:00:00', 'post', 'draft')",
				"CREATE TABLE wp_comments (
					comment_ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					comment_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
					PRIMARY KEY (comment_ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_comments (comment_ID, comment_date_gmt, comment_approved) VALUES
					(11, '2020-01-01 00:00:00', '1'),
					(12, '2020-01-02 12:00:00', '1'),
					(13, '2020-01-03 00:00:00', '1'),
					(14, '2020-01-02 12:00:00', 'spam')",
			)
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID
			FROM wp_posts
			WHERE 1=1
				AND ( wp_posts.post_date_gmt >= '2020-01-02T00:00:00Z'
					AND wp_posts.post_date_gmt <= '2020-01-02T23:59:59Z' )
				AND wp_posts.post_type = 'post'
				AND wp_posts.post_status = 'publish'
			ORDER BY wp_posts.post_date DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID
			FROM wp_posts
			WHERE 1=1
				AND ( wp_posts.post_date_gmt >= '2020-01-02T00:00:00Z'
					AND wp_posts.post_date_gmt <= '2020-01-02T23:59:59Z' )
				AND wp_posts.post_type = 'page'
				AND wp_posts.post_status = 'publish'
			ORDER BY wp_posts.post_date DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID
			FROM wp_posts
			WHERE 1=1
				AND ( wp_posts.post_date_gmt >= '2020-01-02T00:00:00Z'
					AND wp_posts.post_date_gmt <= '2020-01-02T23:59:59Z' )
				AND wp_posts.post_type = 'attachment'
				AND wp_posts.post_status = 'inherit'
			ORDER BY wp_posts.post_date DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_comments.comment_ID
			FROM wp_comments
			WHERE 1=1
				AND ( wp_comments.comment_date_gmt >= '2020-01-02T00:00:00Z'
					AND wp_comments.comment_date_gmt <= '2020-01-02T23:59:59Z' )
				AND comment_approved = '1'
			ORDER BY wp_comments.comment_date_gmt DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_rest_date_queries_after_rfc3339_z_writes_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_posts (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID),
					KEY type_status_date (post_type, post_status, post_date, ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"CREATE TABLE wp_comments (
					comment_ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					comment_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					comment_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
					PRIMARY KEY (comment_ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO wp_posts (ID, post_date, post_date_gmt, post_type, post_status) VALUES
				(1, '2016-01-15T00:00:00Z', '2016-01-15T00:00:00Z', 'post', 'publish'),
				(2, '2016-01-16T00:00:00Z', '2016-01-16T00:00:00Z', 'post', 'publish'),
				(3, '2016-01-17T00:00:00Z', '2016-01-17T00:00:00Z', 'post', 'publish'),
				(4, '2016-01-16T00:00:00Z', '2016-01-16T00:00:00Z', 'page', 'publish'),
				(5, '2016-01-16T00:00:00Z', '2016-01-16T00:00:00Z', 'attachment', 'inherit')"
		);
		$this->assertParityRowCount(
			"INSERT INTO wp_comments (comment_ID, comment_date, comment_date_gmt, comment_approved) VALUES
				(11, '2016-01-15T00:00:00Z', '2016-01-15T00:00:00Z', '1'),
				(12, '2016-01-16T00:00:00Z', '2016-01-16T00:00:00Z', '1'),
				(13, '2016-01-17T00:00:00Z', '2016-01-17T00:00:00Z', '1'),
				(14, '2016-01-16T00:00:00Z', '2016-01-16T00:00:00Z', 'spam')"
		);

		$this->assertParityRows( 'SELECT ID, post_date, post_date_gmt, post_type, post_status FROM wp_posts ORDER BY ID' );
		$this->assertParityRows( 'SELECT comment_ID, comment_date, comment_date_gmt, comment_approved FROM wp_comments ORDER BY comment_ID' );

		foreach (
			array(
				array( 'post', 'publish' ),
				array( 'page', 'publish' ),
				array( 'attachment', 'inherit' ),
			) as $case
		) {
			$this->assertParityRows(
				"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID
				FROM wp_posts
				WHERE 1=1
					AND ( wp_posts.post_date > '2016-01-15T00:00:00Z'
						AND wp_posts.post_date < '2016-01-17T00:00:00Z' )
					AND wp_posts.post_type = '{$case[0]}'
					AND wp_posts.post_status = '{$case[1]}'
				ORDER BY wp_posts.post_date DESC
				LIMIT 0, 10"
			);
			$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		}

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_comments.comment_ID
			FROM wp_comments
			WHERE 1=1
				AND ( wp_comments.comment_date_gmt > '2016-01-15T00:00:00Z'
					AND wp_comments.comment_date_gmt < '2016-01-17T00:00:00Z' )
				AND comment_approved = '1'
			ORDER BY wp_comments.comment_date_gmt DESC
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_rest_modified_order_after_single_digit_hour_update_uses_canonical_datetime(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( "SET SESSION sql_mode = ''" );
		$driver->query(
			"CREATE TABLE wp_posts (
				ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				post_modified DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_modified_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_type VARCHAR(20) NOT NULL DEFAULT 'post',
				post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);
		$driver->query(
			"INSERT INTO wp_posts (ID, post_modified, post_modified_gmt, post_type, post_status) VALUES
				(1, '2020-01-01 00:00:00', '2020-01-01 00:00:00', 'post', 'publish'),
				(2, '2020-01-01 00:00:00', '2020-01-01 00:00:00', 'post', 'publish'),
				(3, '2020-01-01 00:00:00', '2020-01-01 00:00:00', 'post', 'publish')"
		);
		$driver->query(
			"UPDATE wp_posts
			SET post_modified = '2016-04-20 4:26:20', post_modified_gmt = '2016-04-20 4:26:20'
			WHERE ID = 1"
		);
		$driver->query(
			"UPDATE wp_posts
			SET post_modified = '2016-02-01 20:24:02', post_modified_gmt = '2016-02-01 20:24:02'
			WHERE ID = 2"
		);
		$driver->query(
			"UPDATE wp_posts
			SET post_modified = '2016-02-21 12:24:02', post_modified_gmt = '2016-02-21 12:24:02'
			WHERE ID = 3"
		);

		$this->assertSame(
			array(
				array(
					'ID'            => 1,
					'post_modified' => '2016-04-20 04:26:20',
				),
				array(
					'ID'            => 3,
					'post_modified' => '2016-02-21 12:24:02',
				),
				array(
					'ID'            => 2,
					'post_modified' => '2016-02-01 20:24:02',
				),
			),
			$driver->query(
				'SELECT ID, post_modified FROM wp_posts
				WHERE ID IN (1, 2, 3)
				ORDER BY post_modified DESC'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_non_temporal_text_and_blob_write_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE write_coercions (id INTEGER PRIMARY KEY, text_value TEXT, blob_value BLOB)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO write_coercions (id, text_value, blob_value) VALUES
				(1, TRUE, TRUE),
				(2, 0x62, 0x62),
				(3, x'63', x'63'),
				(4, 123.456, 123.456)"
		);
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM write_coercions ORDER BY id' );

		$this->assertParityRowCount( 'UPDATE write_coercions SET text_value = FALSE, blob_value = FALSE WHERE id = 4' );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM write_coercions ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO write_coercions (id, text_value, blob_value) VALUES (2, x'64', x'65')" );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM write_coercions ORDER BY id' );
	}

	public function test_non_strict_numeric_write_coercions_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE numeric_write_coercions (
					id INT PRIMARY KEY,
					int_value INT,
					decimal_value DECIMAL(10,2),
					float_value FLOAT
				)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO numeric_write_coercions (id, int_value, decimal_value, float_value) VALUES
				(1, 'test', 'test', 'test'),
				(2, '', '', '')"
		);
		$this->assertParityRowCount(
			"INSERT INTO numeric_write_coercions SET
				id = 3,
				int_value = 'set-value',
				decimal_value = 'set-value',
				float_value = 'set-value'"
		);
		$this->assertParityRowCount(
			'INSERT INTO numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (4, 7, 8.25, 9.5)'
		);
		$this->assertParityRowCount(
			"UPDATE numeric_write_coercions
			SET int_value = 'update-value',
				decimal_value = 'update-value',
				float_value = 'update-value'
			WHERE id = 4"
		);
		$this->assertParityRowCount(
			"REPLACE INTO numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (2, 'replace-value', 'replace-value', 'replace-value')"
		);
		$this->assertParityRowCount(
			'INSERT INTO numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (3, 10, 11.25, 12.5)
			ON DUPLICATE KEY UPDATE
				int_value = "odku-value",
				decimal_value = "odku-value",
				float_value = "odku-value"'
		);
		$this->assertParityRowCount(
			'INSERT INTO numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (5, "odku-insert", "odku-insert", "odku-insert")
			ON DUPLICATE KEY UPDATE int_value = VALUES(int_value)'
		);

		$this->assertParityRows( 'SELECT id, int_value, decimal_value, float_value FROM numeric_write_coercions ORDER BY id' );
	}

	public function test_non_strict_numeric_update_null_not_null_matches_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE numeric_not_null_coercions (
					id INT PRIMARY KEY,
					int_value INT NOT NULL,
					decimal_value DECIMAL(10,2) NOT NULL,
					float_value FLOAT NOT NULL
				)',
				'INSERT INTO numeric_not_null_coercions (id, int_value, decimal_value, float_value)
					VALUES (1, 7, 8.25, 9.5)',
			)
		);

		$this->assertParityRowCount(
			'UPDATE numeric_not_null_coercions
			SET int_value = NULL,
				decimal_value = NULL,
				float_value = NULL
			WHERE id = 1'
		);
		$this->assertParityRows( 'SELECT id, int_value, decimal_value, float_value FROM numeric_not_null_coercions ORDER BY id' );
		$this->assertParityErrorContains(
			'INSERT INTO numeric_not_null_coercions (id, int_value, decimal_value, float_value)
			VALUES (2, NULL, NULL, NULL)',
			'NOT NULL'
		);
		$this->assertParityRows( 'SELECT id, int_value, decimal_value, float_value FROM numeric_not_null_coercions ORDER BY id' );

		$this->assertParityRowCount(
			'UPDATE numeric_not_null_coercions
			SET int_value = 7,
				decimal_value = 8.25,
				float_value = 9.5
			WHERE id = 1'
		);
		$this->assertParityErrorContains(
			'INSERT INTO numeric_not_null_coercions (id, int_value, decimal_value, float_value)
			VALUES (1, 10, 11.25, 12.5)
			ON DUPLICATE KEY UPDATE int_value = NULL',
			'NOT NULL'
		);
		$this->assertParityRows( 'SELECT id, int_value, decimal_value, float_value FROM numeric_not_null_coercions ORDER BY id' );
	}

	public function test_non_strict_character_implicit_defaults_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE character_implicit_defaults (
					id INT PRIMARY KEY,
					required_tinytext TINYTEXT NOT NULL,
					required_text TEXT NOT NULL,
					required_longtext LONGTEXT NOT NULL,
					required_varchar VARCHAR(20) NOT NULL
				)',
				"INSERT INTO character_implicit_defaults
					(id, required_tinytext, required_text, required_longtext, required_varchar)
					VALUES (3, 'tiny', 'text', 'long', 'varchar')",
			)
		);

		$this->assertParityRowCount( 'INSERT INTO character_implicit_defaults (id) VALUES (1)' );
		$this->assertParityRowCount( 'INSERT INTO character_implicit_defaults SET id = 2' );
		$this->assertParityRowCount(
			'UPDATE character_implicit_defaults
			SET required_tinytext = NULL,
				required_text = NULL,
				required_longtext = NULL,
				required_varchar = NULL
			WHERE id = 3'
		);

		$this->assertParityRows(
			'SELECT id, required_tinytext, required_text, required_longtext, required_varchar
			FROM character_implicit_defaults
			ORDER BY id'
		);
	}

	public function test_non_strict_numeric_insert_select_write_coercions_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE numeric_insert_select_coercions (
					id INT PRIMARY KEY,
					int_value INT NOT NULL,
					decimal_value DECIMAL(10,2) NOT NULL,
					float_value FLOAT NOT NULL,
					nullable_int INT
				)',
				'CREATE TABLE numeric_insert_select_source (
					id INT,
					int_text VARCHAR(20),
					decimal_text VARCHAR(20),
					float_text VARCHAR(20),
					nullable_text VARCHAR(20)
				)',
				"INSERT INTO numeric_insert_select_source VALUES
					(1, 'bad-int', 'bad-decimal', 'bad-float', NULL),
					(2, '7', '8.25', '9.5', '11')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO numeric_insert_select_coercions
				(id, int_value, decimal_value, float_value, nullable_int)
			SELECT id, int_text, decimal_text, float_text, nullable_text
			FROM numeric_insert_select_source
			ORDER BY id'
		);
		$this->assertParityRows(
			'SELECT id, int_value, decimal_value, float_value, nullable_int
			FROM numeric_insert_select_coercions
			ORDER BY id'
		);

		$this->assertParityRowCount(
			'INSERT INTO numeric_insert_select_coercions
				(id, int_value, decimal_value, float_value, nullable_int)
			SELECT 3, NULL, NULL, NULL, NULL'
		);
		$this->assertParityRows(
			'SELECT id, int_value, decimal_value, float_value, nullable_int
			FROM numeric_insert_select_coercions
			ORDER BY id'
		);

		$this->assertParityRowCount(
			"INSERT IGNORE INTO numeric_insert_select_coercions
				(id, int_value, decimal_value, float_value, nullable_int)
			SELECT 1, 'ignored-int', 'ignored-decimal', 'ignored-float', 'ignored-nullable'"
		);
		$this->assertParityRows(
			'SELECT id, int_value, decimal_value, float_value, nullable_int
			FROM numeric_insert_select_coercions
			ORDER BY id'
		);

		$this->assertParityRowCount(
			"REPLACE INTO numeric_insert_select_coercions
				(id, int_value, decimal_value, float_value, nullable_int)
			SELECT 2, 'replace-int', 'replace-decimal', 'replace-float', 'replace-nullable'"
		);
		$this->assertParityRows(
			'SELECT id, int_value, decimal_value, float_value, nullable_int
			FROM numeric_insert_select_coercions
			ORDER BY id'
		);
	}

	public function test_strict_numeric_write_errors_preserve_duckdb_rows(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query(
			'CREATE TABLE strict_numeric_write_coercions (
				id INT PRIMARY KEY,
				int_value INT,
				decimal_value DECIMAL(10,2),
				float_value FLOAT
			)'
		);
		$driver->query(
			'INSERT INTO strict_numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (1, 7, 8.25, 9.5)'
		);

		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO strict_numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (2, 'test', 'test', 'test')",
			'Failed to execute DuckDB INSERT'
		);
		$this->assertSame(
			array(
				array(
					'id'            => 1,
					'int_value'     => 7,
					'decimal_value' => 8.25,
					'float_value'   => 9.5,
				),
			),
			$driver->query( 'SELECT id, int_value, decimal_value, float_value FROM strict_numeric_write_coercions ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assert_duckdb_error_contains(
			$driver,
			"REPLACE INTO strict_numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (1, 'test', 'test', 'test')",
			'Failed to execute DuckDB REPLACE'
		);
		$this->assertSame(
			array(
				array(
					'id'            => 1,
					'int_value'     => 7,
					'decimal_value' => 8.25,
					'float_value'   => 9.5,
				),
			),
			$driver->query( 'SELECT id, int_value, decimal_value, float_value FROM strict_numeric_write_coercions ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO strict_numeric_write_coercions (id, int_value, decimal_value, float_value)
			VALUES (1, 10, 11.25, 12.5)
			ON DUPLICATE KEY UPDATE
				int_value = 'test',
				decimal_value = 'test',
				float_value = 'test'",
			'Failed to execute DuckDB INSERT'
		);
		$this->assertSame(
			array(
				array(
					'id'            => 1,
					'int_value'     => 7,
					'decimal_value' => 8.25,
					'float_value'   => 9.5,
				),
			),
			$driver->query( 'SELECT id, int_value, decimal_value, float_value FROM strict_numeric_write_coercions ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assert_duckdb_error_contains(
			$driver,
			"UPDATE strict_numeric_write_coercions
			SET int_value = 'test',
				decimal_value = 'test',
				float_value = 'test'
			WHERE id = 1",
			'Failed to execute DuckDB UPDATE'
		);
		$this->assertSame(
			array(
				array(
					'id'            => 1,
					'int_value'     => 7,
					'decimal_value' => 8.25,
					'float_value'   => 9.5,
				),
			),
			$driver->query( 'SELECT id, int_value, decimal_value, float_value FROM strict_numeric_write_coercions ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_strict_integer_fractional_writes_reject_like_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE strict_integer_write_rejections (
					id INT PRIMARY KEY,
					int_value INT,
					big_value BIGINT,
					decimal_value DECIMAL(10,2),
					float_value FLOAT
				)',
				'CREATE TABLE strict_integer_write_source (
					id INT,
					int_text VARCHAR(20),
					big_text VARCHAR(20),
					decimal_text VARCHAR(20),
					float_text VARCHAR(20)
				)',
				'INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
					VALUES (1, 7, 8, 9.25, 10.5)',
				"INSERT INTO strict_integer_write_source
					VALUES (2, '4.5', '8', '9.25', '10.5')",
			)
		);

		$state_sql                       = 'SELECT id, int_value, big_value, decimal_value, float_value
			FROM strict_integer_write_rejections
			ORDER BY id';
		$assert_rejects_without_mutation = function ( string $sql ) use ( $state_sql ): void {
			$this->assertParityErrorContains( $sql, 'REAL value in INTEGER column' );
			$this->assertParityRows( $state_sql );
		};

		$assert_rejects_without_mutation(
			"INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (2, '4.5', 8, 9.25, 10.5)"
		);
		$assert_rejects_without_mutation(
			"INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (2, 4, '4e-1', 9.25, 10.5)"
		);
		$assert_rejects_without_mutation(
			"INSERT INTO strict_integer_write_rejections SET
				id = 2,
				int_value = '4.5',
				big_value = 8,
				decimal_value = 9.25,
				float_value = 10.5"
		);
		$assert_rejects_without_mutation(
			"REPLACE INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (1, '4.5', 8, 9.25, 10.5)"
		);
		$assert_rejects_without_mutation(
			"UPDATE strict_integer_write_rejections
			SET int_value = '4.5'
			WHERE id = 1"
		);
		$assert_rejects_without_mutation(
			"INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (1, 7, 8, 9.25, 10.5)
			ON DUPLICATE KEY UPDATE int_value = '4.5'"
		);
		$assert_rejects_without_mutation(
			"INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (1, '4.5', 8, 9.25, 10.5)
			ON DUPLICATE KEY UPDATE int_value = int_value + VALUES(int_value)"
		);
		$assert_rejects_without_mutation(
			'INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			SELECT id, int_text, big_text, decimal_text, float_text
			FROM strict_integer_write_source'
		);
		$assert_rejects_without_mutation(
			"REPLACE INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			SELECT 1, '4.5', 8, 9.25, 10.5"
		);

		$this->assertParityRowCount(
			"INSERT INTO strict_integer_write_rejections (id, int_value, big_value, decimal_value, float_value)
			VALUES (2, '4.0', '8.00', 9.25, 10.5)"
		);
		$this->assertParityRows( $state_sql );
	}

	public function test_show_full_tables_sql_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE _tmp_table (id INT)',
				'CREATE TABLE _tmp_table_2 (id INT)',
				'CREATE TABLE shadow_show (id INT)',
				'CREATE TEMPORARY TABLE shadow_show (temp_id INT)',
				'CREATE TEMPORARY TABLE temp_only_show (id INT)',
			)
		);

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( "SHOW TABLES LIKE '_tmp_table'" );
		$this->assertParityRows( 'SHOW FULL TABLES' );
		$this->assertParityRows( "SHOW FULL TABLES LIKE '_tmp_table'" );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'shadow_show'" );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'temp_only_show'" );
	}

	public function test_view_lifecycle_limitations_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE view_source (id INT, name VARCHAR(20))',
			)
		);

		$this->assertParityErrorContains(
			'CREATE VIEW visible_view AS SELECT id, name FROM view_source',
			'createView'
		);
		$this->assertParityErrorContains(
			'CREATE OR REPLACE VIEW visible_view AS SELECT id FROM view_source',
			'createView'
		);
		$this->assertParityErrorContains( 'SHOW CREATE VIEW visible_view', 'CREATE' );
		$this->assertParityErrorContains( 'DROP VIEW missing_view', 'missing_view' );
		$this->runParitySetup( array( 'DROP VIEW IF EXISTS missing_view' ) );
		$this->assertParityRows( "SHOW FULL TABLES LIKE 'view_source'" );
		$this->assertParityRows(
			"SELECT TABLE_NAME, TABLE_TYPE
			FROM information_schema.tables
			WHERE TABLE_SCHEMA = 'wp' AND TABLE_NAME = 'view_source'"
		);
	}

	public function test_show_admin_metadata_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SHOW COLLATION' );
		$this->assertParityRows( "SHOW COLLATION LIKE 'utf8%'" );
		$this->assertParityRows( "SHOW COLLATION WHERE Collation = 'utf8_bin'" );
		$this->assertParityRows( 'SHOW COLLATION WHERE 0' );
		$this->assertParityRows( "SHOW COLLATION LIKE 'missing%'" );

		$this->assertParityRows( 'SHOW DATABASES' );
		$this->assertParityRows( 'SHOW DATABASES LIKE "w%"' );
		$this->assertParityRows( 'SHOW DATABASES WHERE `Database` = "wp"' );
		$this->assertParityRows( 'SHOW DATABASES WHERE `Database` = "information_schema"' );
		$this->assertParityRows( "SHOW DATABASES LIKE 'missing%'" );
		$this->assertParityRows( 'SHOW SCHEMAS' );
		$this->assertParityRows( "SHOW SCHEMAS LIKE 'wp'" );
		$this->assertParityRows( 'SHOW SCHEMAS WHERE `Database` = "wp"' );
		$this->assertParityRows( 'SHOW SCHEMAS WHERE 0' );

		$this->assertParityRows( 'SHOW GRANTS' );
		$this->assertParityRows( 'SHOW GRANTS FOR current_user()' );
		$this->assertParityRows( 'SHOW GRANTS FOR CURRENT_USER' );
		$this->assertParityRows( 'SHOW GRANTS FOR root@localhost' );
		$this->assertParityRows( "SHOW GRANTS FOR 'root'@'localhost'" );
		$this->assertParityRows( 'SHOW GRANTS FOR usera@localhost' );
		$this->assertParityRows( 'SHOW GRANTS FOR root' );

		$this->assertParityRows( 'SHOW VARIABLES' );
		$this->assertParityRows( "SHOW VARIABLES LIKE 'version'" );
		$this->assertParityRows( "SHOW VARIABLES WHERE Variable_name = 'version'" );
		$this->assertParityRows( 'SHOW VARIABLES WHERE 0' );
		$this->assertParityRows( 'SHOW GLOBAL VARIABLES' );
		$this->assertParityRows( 'SHOW SESSION VARIABLES' );
		$this->assertParityRows( 'SHOW LOCAL VARIABLES' );
		$this->assertParityRows( "SHOW GLOBAL VARIABLES LIKE 'version'" );
		$this->assertParityRows( "SHOW SESSION VARIABLES WHERE Variable_name = 'version'" );
		$this->assertParityRows( "SHOW LOCAL VARIABLES WHERE Variable_name = 'version'" );
	}

	public function test_use_information_schema_collision_state_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE tables (id INT)',
				'INSERT INTO tables (id) VALUES (1), (2)',
			)
		);

		$this->assertParityRows( 'SELECT DATABASE() AS db' );
		$this->assertParityRows( 'SELECT id FROM tables ORDER BY id' );
		$this->assertParityRows(
			"SELECT TABLE_SCHEMA, TABLE_NAME
			FROM information_schema.tables
			WHERE TABLE_NAME = 'tables'
			ORDER BY TABLE_SCHEMA, TABLE_NAME"
		);

		$this->runParitySetup( array( 'USE information_schema' ) );

		$this->assertParityRows( 'SELECT DATABASE() AS db' );
		$this->assertParityRows(
			"SELECT TABLE_SCHEMA, TABLE_NAME
			FROM tables
			WHERE TABLE_NAME = 'tables'
			ORDER BY TABLE_SCHEMA, TABLE_NAME"
		);
		$this->assertParityRows( 'SELECT id FROM wp.tables ORDER BY id' );
		$this->assertParityRows( "SHOW TABLES LIKE 'tables'" );
		$this->assertParityErrorContains( "INSERT INTO tables (TABLE_NAME) VALUES ('x')", 'Access denied' );

		$this->runParitySetup( array( 'USE wp' ) );

		$this->assertParityRows( 'SELECT DATABASE() AS db' );
		$this->assertParityRows( 'SELECT id FROM tables ORDER BY id' );
		$this->assertParityRows( "SHOW TABLES LIKE 'tables'" );
		$this->assertParityErrorContains( 'USE other', "can't use schema" );
	}

	public function test_show_admin_metadata_found_rows_match_sqlite(): void {
		foreach (
			array(
				'SHOW COLLATION',
				"SHOW COLLATION LIKE 'utf8_bin'",
				"SHOW COLLATION LIKE 'missing%'",
				'SHOW DATABASES',
				"SHOW DATABASES LIKE 'missing%'",
				"SHOW SCHEMAS LIKE 'wp'",
				'SHOW SCHEMAS WHERE 0',
				'SHOW GRANTS',
				'SHOW GRANTS FOR current_user()',
				'SHOW VARIABLES',
				'SHOW GLOBAL VARIABLES',
				'SHOW SESSION VARIABLES',
				'SHOW LOCAL VARIABLES',
				'SHOW VARIABLES WHERE 0',
			) as $sql
		) {
			$this->assertParityRows( $sql );
			$this->assertParityRows( 'SELECT FOUND_ROWS()' );
		}
	}

	public function test_transaction_sql_matches_sqlite(): void {
		$this->runParitySetup( array( 'CREATE TABLE tx_items (id INT)' ) );

		$this->runParitySetup(
			array(
				'BEGIN',
				'INSERT INTO tx_items (id) VALUES (1)',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );
		$this->runParitySetup( array( 'ROLLBACK' ) );
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'START TRANSACTION',
				'INSERT INTO tx_items (id) VALUES (2)',
				'COMMIT',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'BEGIN WORK',
				'INSERT INTO tx_items (id) VALUES (3)',
				'BEGIN',
				'INSERT INTO tx_items (id) VALUES (4)',
				'ROLLBACK WORK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'ROLLBACK',
				'COMMIT WORK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM tx_items ORDER BY id' );
	}

	public function test_session_variable_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT @@autocommit, @@session.autocommit, @@big_tables, @@SESSION.big_tables' );
		$this->assertParityRows(
			'SELECT @@GLOBAL.gtid_purged,
				@@GLOBAL.log_bin,
				@@GLOBAL.log_bin_trust_function_creators,
				@@GLOBAL.sql_mode,
				@@SESSION.max_allowed_packet,
				@@SESSION.sql_mode'
		);
		$this->assertParityRows( 'SELECT @@gLoBAL.gTiD_purGed, @@sEssIOn.sqL_moDe' );

		$this->assertParityRowCount( 'SET SESSION autocommit = 1, big_tables = 0' );
		$this->assertParityRows( 'SELECT @@autocommit, @@session.autocommit, @@big_tables, @@session.big_tables' );

		foreach (
			array(
				'SET autocommit = ON, big_tables = OFF',
				'SET autocommit = on, big_tables = off',
				"SET autocommit = 'ON', big_tables = 'OFF'",
				"SET autocommit = 'on', big_tables = 'off'",
				'SET autocommit = TRUE, big_tables = FALSE',
				'SET autocommit = true, big_tables = false',
				'SET autocommit = 1, big_tables = 0',
			) as $sql
		) {
			$this->assertParityRowCount( $sql );
			$this->assertParityRows( 'SELECT @@autocommit, @@big_tables' );
		}

		$this->assertParityRowCount( 'SET default_storage_engine = InnoDB' );
		$this->assertParityRows( 'SELECT @@default_storage_engine, @@SESSION.default_storage_engine' );
		$this->assertParityRowCount( "SET @@session.default_storage_engine = 'MyISAM'" );
		$this->assertParityRows( 'SELECT @@default_storage_engine' );

		$this->assertParityRowCount(
			'SET default_collation_for_utf8mb4 = utf8mb4_0900_ai_ci,
				resultset_metadata = FULL,
				session_track_gtids = OWN_GTID,
				session_track_transaction_info = STATE,
				transaction_isolation = SERIALIZABLE,
				use_secondary_engine = FORCED'
		);
		$this->assertParityRows(
			'SELECT @@default_collation_for_utf8mb4,
				@@resultset_metadata,
				@@session_track_gtids,
				@@session_track_transaction_info,
				@@transaction_isolation,
				@@use_secondary_engine'
		);
		$this->assertParityRowCount( "SET @@session.session_track_transaction_info = 'CHARACTERISTICS'" );
		$this->assertParityRows( 'SELECT @@session.session_track_transaction_info' );

		$this->assertParityRowCount( 'SET autocommit = OFF' );
		$this->assertParityRows( 'SELECT @@autocommit' );
		$this->assertParityRows( 'SELECT @@autocommit AS ac' );
		$this->assertParityRows( 'SELECT @@autocommit + 0' );
		$this->assertParityRows( 'SELECT COALESCE(@@autocommit, 1)' );
		$this->assertParityRowCount( 'SET big_tables = ON' );
		$this->assertParityRows( 'SELECT @@big_tables' );

		$this->assertParityRowCount(
			'SET end_markers_in_json = ON,
				explicit_defaults_for_timestamp = OFF,
				keep_files_on_create = ON,
				old_alter_table = OFF,
				print_identified_with_as_hex = ON,
				require_row_format = OFF,
				select_into_disk_sync = ON,
				session_track_schema = ON,
				session_track_state_change = OFF,
				show_create_table_skip_secondary_engine = ON,
				show_create_table_verbosity = OFF,
				sql_auto_is_null = ON,
				sql_big_selects = OFF,
				sql_buffer_result = ON,
				sql_safe_updates = OFF,
				transaction_read_only = OFF'
		);
		$this->assertParityRows(
			'SELECT @@end_markers_in_json,
				@@explicit_defaults_for_timestamp,
				@@keep_files_on_create,
				@@old_alter_table,
				@@print_identified_with_as_hex,
				@@require_row_format,
				@@select_into_disk_sync,
				@@session_track_schema,
				@@session_track_state_change,
				@@show_create_table_skip_secondary_engine,
				@@show_create_table_verbosity,
				@@sql_auto_is_null,
				@@sql_big_selects,
				@@sql_buffer_result,
				@@sql_safe_updates,
				@@transaction_read_only'
		);
		$this->assertParityRowCount( 'SET @old_safe_updates = @@sql_safe_updates' );
		$this->assertParityRowCount( 'SET @@sql_safe_updates = ON' );
		$this->assertParityRowCount( 'SET @@sql_safe_updates = @old_safe_updates' );
		$this->assertParityRows( 'SELECT @@sql_safe_updates' );

		$this->assertParityRowCount( 'SET sql_warnings = ON' );
		$this->assertParityRows( 'SELECT @@sql_warnings' );
		$this->assertParityRowCount( 'SET @@session.sql_warnings = OFF' );
		$this->assertParityRows( 'SELECT @@sql_warnings, @@SESSION.sql_warnings' );

		$this->assertParityRowCount( 'SET SESSION autocommit = 0' );
		$this->assertParityRowCount( 'SET @@session.big_tables = 1' );
		$this->assertParityRows(
			'SELECT @@autocommit, @@SESSION.autocommit, @@big_tables, @@session.big_tables'
		);

		$this->assertParityRowCount( 'SET autocommit = DEFAULT, big_tables = DEFAULT' );
		$this->assertParityRows( 'SELECT @@autocommit, @@big_tables' );
	}

	public function test_user_variable_sql_matches_sqlite(): void {
		$this->assertParityRows( 'SELECT @missing, @missing AS missing_alias, @missing implicit_alias' );

		$this->assertParityRowCount(
			"SET @my_var = 1, @name := 'Ada', @copy = @name, @mode = @@SQL_MODE, @nothing = NULL"
		);
		$this->assertParityRows(
			'SELECT @MY_VAR, @name AS name, @copy, @mode mode, @nothing AS nothing FROM DUAL'
		);

		$this->assertParityRowCount( 'SET @signed = -2, @decimal = +1.25, @flag = TRUE' );
		$this->assertParityRows( 'SELECT @signed, @decimal, @flag' );

		$this->assertParityRowCount( 'SET @my_var = @my_var + 1' );
		$this->assertParityRows( 'SELECT @my_var' );

		$this->assertParityRowCount( 'SET @my_var = @my_var + 1' );
		$this->assertParityRows( 'SELECT @my_var' );
		$this->assertParityRows( 'SELECT @my_var AS alias, 1' );
		$this->assertParityRows( 'SELECT @my_var + 1' );
		$this->assertParityRows( 'SELECT @my_var + 1 AS expr_var' );
		$this->assertParityRows( 'SELECT COALESCE(@my_var, 1)' );
		$this->assertParityRows( 'SELECT COALESCE(@missing, 1)' );
		$this->assertParityRows( 'SELECT @my_var AS alias, 1 FROM DUAL' );

		$this->assertParityRowCount( 'SET @other = 4, @sum = @my_var + @other' );
		$this->assertParityRows( 'SELECT @sum' );

		$this->assertParityRowCount( 'SET @db = DATABASE(), @version = VERSION()' );
		$this->assertParityRows( 'SELECT @db, @version' );
	}

	public function test_dump_check_variable_backup_and_restore_sql_matches_sqlite(): void {
		$this->assertParityRowCount(
			"SET character_set_client = 'latin1',
				character_set_results = 'latin1',
				collation_connection = latin1_swedish_ci,
				time_zone = '+02:00',
				sql_notes = 1"
		);
		$this->assertParityRowCount( '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' );
		$this->assertParityRowCount( '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;' );
		$this->assertParityRowCount( '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;' );
		$this->assertParityRowCount( '/*!50503 SET NAMES utf8mb4 */;' );
		$this->assertParityRowCount( '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' );
		$this->assertParityRowCount( "/*!40103 SET TIME_ZONE='+00:00' */;" );

		$this->assertParityRowCount(
			'/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;'
		);
		$this->assertParityRows( 'SELECT @OLD_UNIQUE_CHECKS, @@UNIQUE_CHECKS' );

		$this->assertParityRowCount(
			'/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;'
		);
		$this->assertParityRows( 'SELECT @OLD_FOREIGN_KEY_CHECKS, @@FOREIGN_KEY_CHECKS' );

		$this->assertParityRowCount( "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;" );
		$this->assertParityRowCount( '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' );
		$this->assertParityRowCount( '/*!40101 SET @saved_cs_client = @@character_set_client */; ' );
		$this->assertParityRowCount( '/*!50503 SET character_set_client = utf8mb4 */;' );
		$this->assertParityRows(
			'SELECT @OLD_CHARACTER_SET_CLIENT,
				@OLD_CHARACTER_SET_RESULTS,
				@OLD_COLLATION_CONNECTION,
				@OLD_TIME_ZONE,
				@OLD_SQL_MODE,
				@OLD_SQL_NOTES,
				@saved_cs_client,
				@@CHARACTER_SET_CLIENT,
				@@TIME_ZONE,
				@@SQL_MODE,
				@@SQL_NOTES'
		);

		$this->assertParityRowCount( '/*!40101 SET character_set_client = @saved_cs_client */;' );
		$this->assertParityRowCount( '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;' );
		$this->assertParityRowCount( '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;' );
		$this->assertParityRowCount( '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;' );
		$this->assertParityRowCount( '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;' );
		$this->assertParityRowCount( '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;' );
		$this->assertParityRowCount( '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;' );
		$this->assertParityRowCount( '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;' );
		$this->assertParityRowCount( '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;' );
		$this->assertParityRows(
			'SELECT @@CHARACTER_SET_CLIENT,
				@@CHARACTER_SET_RESULTS,
				@@COLLATION_CONNECTION,
				@@TIME_ZONE,
				@@SQL_MODE,
				@@UNIQUE_CHECKS,
				@@FOREIGN_KEY_CHECKS,
				@@SQL_NOTES'
		);

		$this->assertParityRowCount(
			'SET @RESTORED_UNIQUE_CHECKS = 1, @RESTORED_FOREIGN_KEY_CHECKS = "0"'
		);
		$this->assertParityRowCount( 'SET UNIQUE_CHECKS=@RESTORED_UNIQUE_CHECKS' );
		$this->assertParityRowCount( 'SET FOREIGN_KEY_CHECKS=@RESTORED_FOREIGN_KEY_CHECKS' );
		$this->assertParityRows( 'SELECT @@UNIQUE_CHECKS, @@FOREIGN_KEY_CHECKS' );
	}

	public function test_sql_mode_bootstrap_sql_matches_sqlite(): void {
		$this->assertParityRowCount( 'SET NAMES utf8mb4' );
		$this->assertParityRowCount( 'SET CHARSET utf8mb4' );
		$this->assertParityRowCount( 'SET CHARACTER SET utf8mb4' );

		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode AS sql_mode' );
		$this->assertParityRows( 'SELECT @@sql_mode AS mode' );
		$this->assertParityRows( 'SELECT @@ SESSION.sql_mode AS spaced_sql_mode' );

		$this->assertParityRowCount( 'SET NAMES utf8mb4, autocommit = 0' );
		$this->assertParityRows( 'SELECT @@autocommit' );

		$this->assertParityRowCount( "SET CHARACTER SET utf8mb4, sql_mode = 'NO_ZERO_DATE'" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );

		$this->assertParityRowCount(
			"SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"
		);
		$this->assertParityRows( 'SELECT @@SESSION.sql_mode, @@sql_mode' );
	}

	public function test_builtin_system_variables_match_sqlite(): void {
		$this->assertParityRows( 'SELECT @@version, @@version_comment' );
	}

	public function test_lock_unlock_sql_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE lock_items (id INT)',
				'CREATE TEMPORARY TABLE lock_temp (id INT)',
			)
		);

		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES wp.lock_items WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_temp READ, lock_items WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items AS li READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items li READ' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items READ LOCAL' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_items LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items AS li READ LOCAL' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLE lock_items li LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );
		$this->assertParityRowCount( 'LOCK TABLES lock_temp AS lt READ LOCAL, lock_items li LOW_PRIORITY WRITE' );
		$this->assertParityRowCount( 'UNLOCK TABLES' );

		$this->runParitySetup(
			array(
				'BEGIN',
				'INSERT INTO lock_items (id) VALUES (1)',
				'LOCK TABLES lock_items WRITE',
				'INSERT INTO lock_items (id) VALUES (2)',
				'UNLOCK TABLES',
				'ROLLBACK',
			)
		);
		$this->assertParityRows( 'SELECT id FROM lock_items ORDER BY id' );

		$this->runParitySetup(
			array(
				'LOCK TABLES lock_items WRITE',
				'BEGIN',
				'INSERT INTO lock_items (id) VALUES (3)',
				'COMMIT',
				'UNLOCK TABLES',
			)
		);
		$this->assertParityRows( 'SELECT id FROM lock_items ORDER BY id' );

		$this->assertParityErrorContains( 'LOCK TABLES missing_lock_item READ', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES lock_items READ, missing_lock_item WRITE', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES lock_items AS li READ LOCAL, missing_lock_item missing LOW_PRIORITY WRITE', "Table 'wp.missing_lock_item' doesn't exist" );
		$this->assertParityErrorContains( 'LOCK TABLES information_schema.tables READ', "to database 'information_schema'" );
	}

	public function test_update_delete_alias_order_limit_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'b', 1), (2, 'a', 2), (3, 'c', 3)",
			)
		);

		$this->assertParityRowCount( 'UPDATE items SET hits = 9 ORDER BY name LIMIT 1' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "UPDATE items AS i SET i.hits = 7 WHERE i.name = 'b' LIMIT 1" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'UPDATE wp.items SET hits = 6 WHERE id = 3' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'UPDATE items SET hits = 5 LIMIT 0' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( "DELETE FROM items AS i WHERE i.name = 'b' LIMIT 1" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'DELETE FROM wp.items ORDER BY name LIMIT 1' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
		$this->assertParityRowCount( 'DELETE FROM items LIMIT 0' );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_sql_calc_found_rows_and_found_rows_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_found_rows_users (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					user_login VARCHAR(60) NOT NULL DEFAULT '',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_found_rows_users (user_login) VALUES
					('ada'),
					('grace'),
					('katherine')",
			)
		);

		$this->assertParityRows(
			'SELECT SQL_CALC_FOUND_ROWS ID, user_login FROM wp_found_rows_users ORDER BY ID LIMIT 2'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_sql_calc_found_rows_scalar_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_found_rows_coercion_users (
					ID BIGINT(20) UNSIGNED NOT NULL,
					user_login VARCHAR(60) NOT NULL DEFAULT '',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				'CREATE TABLE wp_found_rows_coercion_usermeta (
					umeta_id BIGINT(20) UNSIGNED NOT NULL,
					user_id BIGINT(20) UNSIGNED NOT NULL,
					meta_key VARCHAR(255),
					meta_value LONGTEXT,
					PRIMARY KEY (umeta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_found_rows_coercion_users (ID, user_login) VALUES
					(0, 'zero'),
					(1, 'one'),
					(2, 'two')",
				"INSERT INTO wp_found_rows_coercion_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES
					(10, 0, 'user_age', 'abc'),
					(11, 1, 'user_age', '10'),
					(12, 2, 'user_age', '2'),
					(13, 1, 'empty_age', NULL),
					(14, 1, 'numeric_prefix', '10abc'),
					(15, 1, 'numeric_prefix', ' 11x'),
					(16, 1, 'numeric_prefix', '-2.5z'),
					(17, 1, 'numeric_prefix', '+3e2tail'),
					(18, 1, 'numeric_prefix', '.75q'),
					(19, 1, 'numeric_prefix', 'abc'),
					(20, 1, 'numeric_prefix', ''),
					(21, 1, 'numeric_prefix', '0x10'),
					(22, 1, 'numeric_prefix', NULL)",
				'CREATE TABLE wp_found_rows_coercion_options (
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_name VARCHAR(191) NOT NULL DEFAULT \'\',
					option_value LONGTEXT,
					PRIMARY KEY (option_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_found_rows_coercion_options (option_id, option_name, option_value) VALUES
					(1, 'a', '10'),
					(2, 'b', '010'),
					(3, 'c', '10abc'),
					(4, 'd', ' 11x'),
					(5, 'e', 'abc'),
					(6, 'f', ''),
					(7, 'g', NULL)",
				'CREATE TABLE wp_found_rows_nullable_ids (
					ID BIGINT(20),
					label VARCHAR(20)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_found_rows_nullable_ids (ID, label) VALUES
					(NULL, 'null'),
					(1, 'one')",
				'CREATE TABLE wp_plugin_text_ids (
					id VARCHAR(20),
					count VARCHAR(20),
					label VARCHAR(20)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_plugin_text_ids (id, count, label) VALUES
					('2', '5', 'two'),
					('abc', '9', 'abc')",
			)
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID = 'yololololo' OR user_login LIKE '%yololololo%'
			ORDER BY ID
			LIMIT 0, 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID = '02'
			ORDER BY ID"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID BETWEEN '1' AND 'yololololo'
			ORDER BY ID"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID NOT BETWEEN '1' AND 'yololololo'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID IN ('1', 'yololololo', '02')
			ORDER BY ID"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID IN (1, NULL, 'yololololo', 2)
			ORDER BY ID"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID IN (1 + 1, 'bad')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID NOT IN (1 + 1, 'bad')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID NOT IN ('1', 'yololololo', '02')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID NOT IN (1, NULL, 'yololololo')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_found_rows_coercion_users
			WHERE ID LIKE '1%'
			ORDER BY ID"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT ID
			FROM wp_found_rows_coercion_users
			WHERE ID NOT LIKE '1%'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT wp_found_rows_coercion_users.ID
			FROM wp_found_rows_coercion_users
			WHERE wp_found_rows_coercion_users.ID IN ('1', 'bad')
			ORDER BY wp_found_rows_coercion_users.ID"
		);

		$this->assertParityRows(
			"SELECT wp_found_rows_coercion_users.ID
			FROM wp_found_rows_coercion_users
			WHERE wp_found_rows_coercion_users.ID BETWEEN '1' AND 'bad'
			ORDER BY wp_found_rows_coercion_users.ID"
		);

		$this->assertParityRows(
			"SELECT wp_found_rows_coercion_users.ID
			FROM wp_found_rows_coercion_users
			WHERE wp_found_rows_coercion_users.ID IN (1, 'bad')
			ORDER BY wp_found_rows_coercion_users.ID"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE user_id IN ('1', 'bad', '2')
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE user_id BETWEEN '1' AND 'bad'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE user_id LIKE '1%'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE user_id IN (1, NULL, 'bad', 2)
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS wp_found_rows_coercion_users.ID
			FROM wp_found_rows_coercion_users
				INNER JOIN wp_found_rows_coercion_usermeta
					ON ( wp_found_rows_coercion_users.ID = wp_found_rows_coercion_usermeta.user_id )
			WHERE wp_found_rows_coercion_usermeta.meta_key = 'user_age'
			ORDER BY wp_found_rows_coercion_usermeta.meta_value+0 ASC
			LIMIT 0, 2"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			'SELECT meta_value + 0 AS coerced
			FROM wp_found_rows_coercion_usermeta
			WHERE umeta_id = 13'
		);

		$this->assertParityRows(
			"SELECT umeta_id, meta_value + 0 AS coerced
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value IS NOT NULL
			ORDER BY meta_value + 0 ASC, umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix'
			ORDER BY meta_value + 0 ASC, umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix'
			ORDER BY meta_value + 0, umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix'
			ORDER BY meta_value + 0 DESC, umeta_id"
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			ORDER BY option_value + 0 ASC, option_id'
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND CAST(meta_value AS SIGNED) LIKE '10%'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND CAST(meta_value AS SIGNED) NOT LIKE '10%'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value LIKE 10
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT LIKE 10
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND 10 LIKE meta_value
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND 10 NOT LIKE meta_value
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value LIKE 10
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value NOT LIKE 10
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE 10 LIKE option_value
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE 10 NOT LIKE option_value
			ORDER BY option_id'
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value < 11
			ORDER BY umeta_id"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value < 10.5
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value = -2.5
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND 11 > meta_value
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND 10.5 > meta_value
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value BETWEEN 10 AND 11
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value BETWEEN 10 AND 'abc'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value BETWEEN '10' AND 11
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value BETWEEN -2.5 AND +3
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value BETWEEN 10.0 AND 10.9
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT BETWEEN 10 AND 11
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT BETWEEN 10 AND 'abc'
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value IN (10, 11)
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value IN (-2.5, +3, 10.5)
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value IN (10, NULL, 'abc', 10.5)
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value IN (10 + 0, 10.50 + 0, +3 * 100, 'abc')
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT IN (10, 11)
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT IN (10, NULL, 'abc')
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value NOT IN (10 + 0, 'abc')
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND meta_value <> 10
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			"SELECT umeta_id
			FROM wp_found_rows_coercion_usermeta
			WHERE meta_key = 'numeric_prefix' AND 10 != meta_value
			ORDER BY umeta_id"
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value < 11
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value < 10.5
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE 11 > option_value
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value BETWEEN 10 AND 11
			ORDER BY option_id'
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value BETWEEN 10 AND 'abc'
			ORDER BY option_id"
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value <> 10
			ORDER BY option_id'
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value NOT BETWEEN 10 AND 11
			ORDER BY option_id'
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value NOT BETWEEN 10 AND 'abc'
			ORDER BY option_id"
		);

		$this->assertParityRows(
			'SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value NOT IN (10, 11)
			ORDER BY option_id'
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value IN (10, NULL, '10abc')
			ORDER BY option_id"
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_found_rows_coercion_options
			WHERE option_value NOT IN (10, NULL, '10abc')
			ORDER BY option_id"
		);

		$this->assertParityRows(
			"SELECT label
			FROM wp_found_rows_nullable_ids
			WHERE ID != 'abc'
			ORDER BY label"
		);

		$this->assertParityRows(
			"SELECT id
			FROM wp_plugin_text_ids
			WHERE id < '10'
			ORDER BY id"
		);

		$this->assertParityRows(
			"SELECT wp_plugin_text_ids.count
			FROM wp_plugin_text_ids
			WHERE wp_plugin_text_ids.count < '10'
			ORDER BY wp_plugin_text_ids.count"
		);
	}

	public function test_constant_expression_in_list_items_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_expression_in_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_expression_in_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '11'),
					(2, 1, 'abc'),
					(3, 2, '10'),
					(4, 2, '10.5'),
					(5, 3, '3'),
					(6, 3, NULL)",
				'CREATE TABLE wp_expression_in_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_parent BIGINT(20) UNSIGNED NOT NULL,
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				'INSERT INTO wp_expression_in_posts (ID, post_parent) VALUES
					(1, 0),
					(2, 1),
					(3, 3),
					(4, 11)',
			)
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_expression_in_postmeta
			WHERE meta_value IN (10 + 1, 'abc')
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			'SELECT meta_id
			FROM wp_expression_in_postmeta
			WHERE meta_value IN (10.50 + 0, +3 * 1)
			ORDER BY meta_id'
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_expression_in_postmeta
			WHERE meta_value NOT IN (10 + 1, 'abc')
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_in_posts
			WHERE post_parent IN (10 + 1, 'abc')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_in_posts
			WHERE post_parent NOT IN (10 + 1, 'abc')
			ORDER BY ID"
		);
	}

	public function test_chained_constant_expression_in_list_items_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_chained_expression_in_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_in_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '10'),
					(2, 1, '11'),
					(3, 2, '13'),
					(4, 2, 'abc'),
					(5, 3, NULL),
					(6, 3, '6')",
				'CREATE TABLE wp_chained_expression_in_options (
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_value LONGTEXT,
					PRIMARY KEY (option_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_in_options (option_id, option_value) VALUES
					(1, '13'),
					(2, 'abc'),
					(3, NULL),
					(4, '10.5')",
				'CREATE TABLE wp_chained_expression_in_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_parent BIGINT(20) UNSIGNED NOT NULL,
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				'INSERT INTO wp_chained_expression_in_posts (ID, post_parent) VALUES
					(1, 0),
					(2, 11),
					(3, 13),
					(4, 6)',
			)
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_chained_expression_in_postmeta
			WHERE meta_value IN (10 + 1 + 2, 'abc')
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_chained_expression_in_postmeta
			WHERE meta_value NOT IN (10 + 1 + 2, 'abc')
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_chained_expression_in_options
			WHERE option_value IN (10 + 1 + 2, NULL, 'abc')
			ORDER BY option_id"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_chained_expression_in_posts
			WHERE post_parent IN (1 + 2 + 8, 'bad')
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_chained_expression_in_posts
			WHERE post_parent NOT IN (1 + 2 + 8, 'bad')
			ORDER BY ID"
		);
	}

	public function test_constant_expression_between_bounds_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_expression_between_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_expression_between_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '11'),
					(2, 1, 'abc'),
					(3, 2, '10'),
					(4, 2, '10.5'),
					(5, 3, '3'),
					(6, 3, NULL),
					(7, 4, '12')",
				'CREATE TABLE wp_expression_between_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_parent BIGINT(20) UNSIGNED NOT NULL,
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				'INSERT INTO wp_expression_between_posts (ID, post_parent) VALUES
					(1, 0),
					(2, 1),
					(3, 2),
					(4, 3),
					(5, 11)',
			)
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_expression_between_postmeta
			WHERE meta_value BETWEEN 10 + 1 AND 'abc'
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_expression_between_postmeta
			WHERE meta_value NOT BETWEEN 10 + 1 AND 'abc'
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			'SELECT meta_id
			FROM wp_expression_between_postmeta
			WHERE meta_value BETWEEN 10.50 + 0 AND +3 * 4
			ORDER BY meta_id'
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_expression_between_postmeta
			WHERE meta_value BETWEEN '10' AND 10 + 1
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			'SELECT meta_id
			FROM wp_expression_between_postmeta
			WHERE meta_value BETWEEN 10 + 0 AND NULL
			ORDER BY meta_id'
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_between_posts
			WHERE post_parent BETWEEN 1 AND 'bad'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_between_posts
			WHERE post_parent NOT BETWEEN 1 AND 'bad'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_between_posts
			WHERE post_parent BETWEEN 1 + 1 AND 'bad'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_between_posts
			WHERE post_parent NOT BETWEEN 1 + 1 AND 'bad'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_expression_between_posts
			WHERE post_parent BETWEEN 'bad' AND 3 + 0
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT SQL_CALC_FOUND_ROWS ID
			FROM wp_expression_between_posts
			WHERE post_parent BETWEEN 1 + 1 AND 'bad'
			ORDER BY ID
			LIMIT 10"
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_chained_constant_expression_between_bounds_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_chained_expression_between_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_between_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '10'),
					(2, 1, '11'),
					(3, 2, '13'),
					(4, 2, 'abc'),
					(5, 3, NULL),
					(6, 3, '6')",
				'CREATE TABLE wp_chained_expression_between_options (
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_value LONGTEXT,
					PRIMARY KEY (option_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_between_options (option_id, option_value) VALUES
					(1, '10'),
					(2, '11'),
					(3, '13'),
					(4, 'abc'),
					(5, NULL)",
				'CREATE TABLE wp_chained_expression_between_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_parent BIGINT(20) UNSIGNED NOT NULL,
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				'INSERT INTO wp_chained_expression_between_posts (ID, post_parent) VALUES
					(1, 0),
					(2, 11),
					(3, 13),
					(4, 6)',
			)
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_chained_expression_between_postmeta
			WHERE meta_value BETWEEN 10 + 1 + 2 AND 'abc'
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT meta_id
			FROM wp_chained_expression_between_postmeta
			WHERE meta_value NOT BETWEEN 10 + 1 + 2 AND 'abc'
			ORDER BY meta_id"
		);

		$this->assertParityRows(
			"SELECT option_id
			FROM wp_chained_expression_between_options
			WHERE option_value BETWEEN '10' AND 10 + 1 + 2
			ORDER BY option_id"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_chained_expression_between_posts
			WHERE post_parent BETWEEN 1 + 2 + 8 AND 'bad'
			ORDER BY ID"
		);

		$this->assertParityRows(
			"SELECT ID
			FROM wp_chained_expression_between_posts
			WHERE post_parent NOT BETWEEN 1 + 2 + 8 AND 'bad'
			ORDER BY ID"
		);
	}

	public function test_constant_expression_text_value_scalar_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_expression_scalar_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_expression_scalar_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '10'),
					(2, 1, '11'),
					(3, 2, '11.5'),
					(4, 2, '3'),
					(5, 3, 'abc'),
					(6, 3, '10.5'),
					(7, 4, NULL),
					(8, 4, '-3')",
				'CREATE TABLE wp_expression_scalar_options (
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_value LONGTEXT,
					PRIMARY KEY (option_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_expression_scalar_options (option_id, option_value) VALUES
					(1, '10'),
					(2, '11'),
					(3, 'abc'),
					(4, NULL)",
			)
		);

		foreach (
			array(
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE meta_value = 10 + 1 ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE meta_value <> 10 + 1 ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE meta_value < 10 + 1 ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE meta_value > 10 + 1 ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE 10 + 1 = meta_value ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE 10 + 1 <> meta_value ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE 10 + 1 < meta_value ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE 10 + 1 > meta_value ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE meta_value = 10.50 + 0 ORDER BY meta_id',
				'SELECT meta_id FROM wp_expression_scalar_postmeta WHERE +3 * 1 = meta_value ORDER BY meta_id',
				'SELECT option_id FROM wp_expression_scalar_options WHERE option_value < 10 + 1 ORDER BY option_id',
				'SELECT option_id FROM wp_expression_scalar_options WHERE +3 * 1 < option_value ORDER BY option_id',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}

		$this->assertParityRows(
			'SELECT SQL_CALC_FOUND_ROWS meta_id
			FROM wp_expression_scalar_postmeta
			WHERE meta_value < 10 + 1
			ORDER BY meta_id
			LIMIT 10'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_chained_constant_expression_text_value_scalar_comparisons_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_chained_expression_scalar_postmeta (
					meta_id BIGINT(20) UNSIGNED NOT NULL,
					post_id BIGINT(20) UNSIGNED NOT NULL,
					meta_value LONGTEXT,
					PRIMARY KEY (meta_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_scalar_postmeta (meta_id, post_id, meta_value) VALUES
					(1, 1, '10'),
					(2, 1, '11'),
					(3, 2, '13'),
					(4, 2, 'abc'),
					(5, 3, NULL),
					(6, 3, '6'),
					(7, 4, '10.5')",
				'CREATE TABLE wp_chained_expression_scalar_options (
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_value LONGTEXT,
					PRIMARY KEY (option_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO wp_chained_expression_scalar_options (option_id, option_value) VALUES
					(1, '10'),
					(2, '11'),
					(3, '13'),
					(4, 'abc'),
					(5, NULL),
					(6, '10.5')",
			)
		);

		foreach (
			array(
				'SELECT meta_id FROM wp_chained_expression_scalar_postmeta WHERE meta_value = 10 + 1 + 2 ORDER BY meta_id',
				'SELECT meta_id FROM wp_chained_expression_scalar_postmeta WHERE meta_value <> 10 + 1 + 2 ORDER BY meta_id',
				'SELECT meta_id FROM wp_chained_expression_scalar_postmeta WHERE meta_value < 10 + 1 + 2 ORDER BY meta_id',
				'SELECT meta_id FROM wp_chained_expression_scalar_postmeta WHERE 10 + 1 + 2 = meta_value ORDER BY meta_id',
				'SELECT meta_id FROM wp_chained_expression_scalar_postmeta WHERE 10 + 1 + 2 < meta_value ORDER BY meta_id',
				'SELECT option_id FROM wp_chained_expression_scalar_options WHERE option_value < 10 + 1 + 2 ORDER BY option_id',
				'SELECT option_id FROM wp_chained_expression_scalar_options WHERE option_value = 10.50 + 0 + 0 ORDER BY option_id',
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}

		$this->assertParityRows(
			'SELECT SQL_CALC_FOUND_ROWS meta_id
			FROM wp_chained_expression_scalar_postmeta
			WHERE 10 + 1 + 2 < meta_value
			ORDER BY meta_id
			LIMIT 10'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_found_rows_state_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_found_rows_state (id INT, label VARCHAR(20))',
				"INSERT INTO wp_found_rows_state VALUES (1, 'one'), (2, 'two'), (3, 'three')",
			)
		);

		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'SELECT id, label FROM wp_found_rows_state ORDER BY id' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRowCount( "UPDATE wp_found_rows_state SET label = 'updated' WHERE id = 1" );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id, label FROM wp_found_rows_state ORDER BY id' );
		$this->runParitySetup( array( 'CREATE TABLE wp_found_rows_state_extra (id INT)' ) );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'DESCRIBE wp_found_rows_state' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_failed_selects_reset_found_rows_state_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_found_rows_reset (id INT)',
				'INSERT INTO wp_found_rows_reset VALUES (1), (2), (3)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityErrorContains( 'SELECT * FROM missing_found_rows_reset', 'missing_found_rows_reset' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertParityRows( 'SELECT id FROM wp_found_rows_reset ORDER BY id' );
		$this->assertParityErrorContains(
			'SELECT SQL_CALC_FOUND_ROWS * FROM missing_found_rows_reset LIMIT 1',
			'missing_found_rows_reset'
		);
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
	}

	public function test_select_posts_wildcard_group_by_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_author BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					post_title TEXT NOT NULL,
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_posts (ID, post_author, post_title) VALUES
					(1, 10, 'first'),
					(2, 20, 'second')",
			)
		);

		$this->assertParityRows(
			'SELECT wp_posts.* FROM wp_posts GROUP BY wp_posts.ID ORDER BY wp_posts.ID'
		);
	}

	public function test_select_terms_aggregate_group_by_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_terms (
					term_id BIGINT(20) UNSIGNED NOT NULL,
					name VARCHAR(200) NOT NULL DEFAULT '',
					slug VARCHAR(200) NOT NULL DEFAULT '',
					term_group BIGINT(10) NOT NULL DEFAULT 0,
					PRIMARY KEY (term_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"CREATE TABLE wptests_term_taxonomy (
					term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
					term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					taxonomy VARCHAR(32) NOT NULL DEFAULT '',
					description LONGTEXT NOT NULL,
					parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					count BIGINT(20) NOT NULL DEFAULT 0,
					PRIMARY KEY (term_taxonomy_id),
					UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				'CREATE TABLE wptests_term_relationships (
					object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					term_order INT(11) NOT NULL DEFAULT 0,
					PRIMARY KEY (object_id, term_taxonomy_id),
					KEY term_taxonomy_id (term_taxonomy_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_terms (term_id, name, slug, term_group) VALUES
					(1, 'Alpha', 'alpha', 0),
					(2, 'Beta', 'beta', 0)",
				"INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
					(101, 1, 'wptests_tax', 'First description', 0, 1),
					(102, 2, 'wptests_tax', 'Second description', 0, 1)",
				"INSERT INTO wptests_posts (ID, post_type, post_status) VALUES
					(201, 'post', 'publish'),
					(202, 'post', 'publish'),
					(203, 'page', 'publish')",
				'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
					(201, 101, 0),
					(202, 102, 0),
					(203, 101, 0)',
			)
		);

		$this->assertParityRows(
			"SELECT DISTINCT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, COUNT(p.post_type) AS count
			FROM wptests_terms AS t
				INNER JOIN wptests_term_taxonomy AS tt ON t.term_id = tt.term_id
				LEFT JOIN wptests_term_relationships AS r ON r.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN wptests_posts AS p ON p.ID = r.object_id
			WHERE tt.taxonomy IN ('wptests_tax')
				AND (p.post_type = 'post' OR p.post_type IS NULL)
				AND (p.post_status = 'publish')
			GROUP BY t.term_id
			ORDER BY t.name ASC"
		);
	}

	public function test_select_split_shared_term_probe_group_by_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_terms (
					term_id BIGINT(20) UNSIGNED NOT NULL,
					name VARCHAR(200) NOT NULL DEFAULT '',
					slug VARCHAR(200) NOT NULL DEFAULT '',
					term_group BIGINT(10) NOT NULL DEFAULT 0,
					PRIMARY KEY (term_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"CREATE TABLE wptests_term_taxonomy (
					term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
					term_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					taxonomy VARCHAR(32) NOT NULL DEFAULT '',
					description LONGTEXT NOT NULL,
					parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
					count BIGINT(20) NOT NULL DEFAULT 0,
					PRIMARY KEY (term_taxonomy_id),
					UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_terms (term_id, name, slug, term_group) VALUES
					(1, 'Alpha', 'alpha', 0),
					(3, 'Shared', 'shared', 0)",
				"INSERT INTO wptests_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
					(101, 1, 'category', 'Alpha category', 0, 0),
					(301, 3, 'category', 'Shared category', 0, 0),
					(302, 3, 'post_tag', 'Shared tag', 0, 0)",
			)
		);

		$this->assertParityRows(
			'SELECT tt.term_id, t.*, count(*) AS term_tt_count
			FROM wptests_term_taxonomy tt
			LEFT JOIN wptests_terms t ON t.term_id = tt.term_id
			GROUP BY t.term_id
			HAVING term_tt_count > 1
			LIMIT 1'
		);
	}

	public function test_select_archive_year_month_group_order_by_post_date_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
					(1, '2024-02-01 00:00:00', 'post', 'publish'),
					(2, '2024-01-10 00:00:00', 'post', 'publish'),
					(3, '2023-12-31 00:00:00', 'post', 'publish'),
					(4, '2024-02-02 00:00:00', 'page', 'publish'),
					(5, '2024-03-01 00:00:00', 'post', 'draft')",
			)
		);

		$this->assertParityRows(
			"SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts
			FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY YEAR(post_date), MONTH(post_date)
			ORDER BY post_date DESC"
		);
	}

	public function test_select_archive_week_group_date_format_order_by_post_date_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
					post_type VARCHAR(20) NOT NULL DEFAULT 'post',
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					PRIMARY KEY (ID)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wptests_posts (ID, post_date, post_type, post_status) VALUES
					(1, '2024-02-01 00:00:00', 'post', 'publish'),
					(2, '2024-01-10 00:00:00', 'post', 'publish'),
					(3, '2023-12-31 00:00:00', 'post', 'publish'),
					(4, '2024-02-02 00:00:00', 'page', 'publish'),
					(5, '2024-03-01 00:00:00', 'post', 'draft')",
			)
		);

		$this->assertParityRows(
			"SELECT DISTINCT WEEK( `post_date`, 1 ) AS `week`,
				YEAR( `post_date` ) AS `yr`,
				DATE_FORMAT( `post_date`, '%Y-%m-%d' ) AS `yyyymmdd`,
				count( `ID` ) AS `posts`
			FROM `wptests_posts`
			WHERE post_type = 'post' AND post_status = 'publish'
			GROUP BY WEEK( `post_date`, 1 ), YEAR( `post_date` )
			ORDER BY `post_date` DESC"
		);
	}

	public function test_select_index_hints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_hint_posts (
					ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
					post_title VARCHAR(200) NOT NULL DEFAULT '',
					PRIMARY KEY (ID),
					KEY post_status (post_status)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_hint_posts (post_status, post_title) VALUES
					('publish', 'first'),
					('draft', 'second'),
					('publish', 'third')",
			)
		);

		$this->assertParityRows(
			"SELECT post_title
			FROM wp_hint_posts FORCE INDEX (PRIMARY, post_status)
			WHERE post_status = 'publish'
			ORDER BY ID"
		);
		$this->assertParityRows(
			'SELECT post_status, COUNT(*) AS total
			FROM wp_hint_posts USE KEY FOR GROUP BY (post_status)
			GROUP BY post_status
			ORDER BY post_status'
		);
		$this->assertParityRows(
			'SELECT post_title
			FROM wp_hint_posts IGNORE INDEX FOR ORDER BY (post_status)
			ORDER BY ID DESC
			LIMIT 1'
		);
	}

	public function test_row_locking_clauses_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_lock_items (name VARCHAR(255), value VARCHAR(255))',
				"INSERT INTO wp_lock_items (name, value) VALUES ('test_lock', '123')",
			)
		);

		foreach (
			array(
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR SHARE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' LOCK IN SHARE MODE",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE SKIP LOCKED",
				"SELECT value FROM wp_lock_items WHERE name = 'test_lock' FOR UPDATE NOWAIT",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_order_by_field_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_field_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value VARCHAR(191) NOT NULL DEFAULT '',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_field_options (option_name, option_value) VALUES
					('User 0000019', 'second'),
					('User 0000020', 'third'),
					('User 0000018', 'first')",
			)
		);

		$this->assertParityRows(
			"SELECT FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020') AS sorting_order
			FROM wp_field_options
			ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
		);
		$this->assertParityRows(
			"SELECT option_value
			FROM wp_field_options
			ORDER BY FIELD(option_name, 'User 0000018', 'User 0000019', 'User 0000020')"
		);
		$this->assertParityRows(
			"SELECT FIELD('b', 'A', 'B') AS case_match,
			FIELD(NULL, 'A', 'B') AS null_match,
			FIELD('z', 'A', 'B') AS no_match"
		);
	}

	public function test_joined_update_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE posts (id INT, status VARCHAR(20), score INT)',
				'CREATE TABLE post_updates (post_id INT, new_status VARCHAR(20), bump INT, flag VARCHAR(20))',
				"INSERT INTO posts VALUES (1, 'draft', 0), (2, 'draft', 0), (3, 'publish', 5)",
				"INSERT INTO post_updates VALUES
					(1, 'publish', 10, 'apply'),
					(2, 'private', 20, 'skip'),
					(3, 'archive', 30, 'apply')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE posts p
			JOIN post_updates u ON u.post_id = p.id
			SET p.status = u.new_status, p.score = p.score + u.bump
			WHERE u.flag = 'apply'"
		);
		$this->assertParityRows( 'SELECT id, status, score FROM posts ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE posts p, post_updates u
			SET p.status = 'queued'
			WHERE p.id = u.post_id AND u.flag = 'skip'"
		);
		$this->assertParityRows( 'SELECT id, status, score FROM posts ORDER BY id' );
	}

	public function test_cross_joined_update_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a CROSS JOIN t2 b
			SET a.note = 'cross'
			WHERE b.id = 4"
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'UPDATE t1 a CROSS JOIN t2 b ON a.id = b.id
			SET a.note = b.note
			WHERE b.flag = 1'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_straight_joined_update_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (2, 'b2', 0), (3, 'b3', 1), (4, 'b4', 1)",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a STRAIGHT_JOIN t2 b ON a.id = b.id
			SET a.note = 'straight', a.only_t1 = a.only_t1 + b.flag
			WHERE b.flag = 1"
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_left_and_right_joined_update_match_sqlite_current_rewrite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a LEFT JOIN t2 b ON a.id = b.id
			SET a.note = 'left'"
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE t1 a RIGHT JOIN t2 b ON a.id = b.id
			SET a.note = 'right'"
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_natural_joined_update_matches_sqlite_current_rewrite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a NATURAL JOIN t2 b
			SET a.note = 'natural'"
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_update_using_columns_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

		$this->assertParityRowCount( "UPDATE t1 a JOIN t2 b USING (id) SET a.note = 'using' WHERE b.id = 5" );
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_update_non_first_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'x'), (2, 'y'), (3, 'q')",
				'CREATE TABLE tree (id INT, parent_id INT, label VARCHAR(20))',
				"INSERT INTO tree VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 1, 'sibling')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET b.note = 'z'
			WHERE a.id IN (1, 3)"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE t1 a, t2 b
			SET b.note = 'comma'
			WHERE a.id = b.id AND a.id = 2"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"UPDATE tree parent JOIN tree child ON child.parent_id = parent.id
			SET child.label = 'claimed'
			WHERE parent.id = 1 AND child.id = 2"
		);
		$this->assertParityRows( 'SELECT id, label FROM tree ORDER BY id' );
	}

	public function test_multi_target_joined_update_rejection_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b')",
				"INSERT INTO t2 VALUES (1, 'x'), (3, 'z')",
			)
		);

		$this->assertParityErrorContains(
			"UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET a.note = 'target', b.note = 'source'",
			'UPDATE statement modifying multiple tables'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityErrorContains(
			"UPDATE t1 a, t2 b
			SET a.note = 'target', b.note = 'source'
			WHERE a.id = b.id",
			'UPDATE statement modifying multiple tables'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityErrorContains(
			"UPDATE t1 a CROSS JOIN t2 b
			SET a.note = 'target', b.note = 'source'
			WHERE b.id = 3",
			'UPDATE statement modifying multiple tables'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );
	}

	public function test_joined_update_unqualified_unique_unaliased_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

			$this->assertParityRowCount(
				'UPDATE t1 JOIN t2 ON t1.id = t2.id
				SET only_t1 = 99
				WHERE t2.flag = 1'
			);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_update_unqualified_unique_aliased_target_matches_sqlite_current_rewrite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30), (4, 'a4', 40)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (5, 'b5', 1)",
			)
		);

		$this->assertParityRowCount(
			'UPDATE t1 a JOIN t2 b ON a.id = b.id
			SET only_t1 = 99
			WHERE b.flag = 1'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_update_derived_table_claim_query_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_actionscheduler_actions (
					action_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					status VARCHAR(20) NOT NULL,
					scheduled_date_gmt DATETIME NULL,
					priority TINYINT UNSIGNED NOT NULL DEFAULT '10',
					attempts INT(11) NOT NULL DEFAULT '0',
					claim_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					last_attempt_gmt DATETIME NULL,
					last_attempt_local DATETIME NULL,
					PRIMARY KEY (action_id)
				)",
				"INSERT INTO wp_actionscheduler_actions
					(action_id, status, scheduled_date_gmt, priority, attempts, claim_id)
				VALUES
					(1, 'pending', '2025-09-03 12:00:00', 10, 0, 0),
					(2, 'pending', '2025-09-03 12:10:00', 5, 0, 0)",
			)
		);

		$this->runParitySetup(
			array(
				"UPDATE wp_actionscheduler_actions t1
			JOIN (
				SELECT action_id
				FROM wp_actionscheduler_actions
				WHERE claim_id = 0
				AND scheduled_date_gmt <= '2025-09-03 12:23:55'
				AND status = 'pending'
				ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC
				LIMIT 2
				FOR UPDATE
			) t2 ON t1.action_id = t2.action_id
			SET claim_id = 37,
				last_attempt_gmt = '2025-09-03 12:23:55',
				last_attempt_local = '2025-09-03 12:23:55'",
			)
		);
		$this->assertParityRows(
			'SELECT action_id, claim_id, last_attempt_gmt
			FROM wp_actionscheduler_actions
			ORDER BY action_id'
		);
	}

	public function test_multi_table_delete_transient_cleanup_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO wp_options (option_name, option_value, autoload) VALUES
					('_transient_tag4', 'tag4', 'no'),
					('_transient_timeout_tag4', '1', 'no'),
					('_transient_tag5', 'tag5', 'no'),
					('_transient_timeout_tag5', '9999999999', 'no'),
					('_site_transient_tag1', 'tag1', 'no'),
					('_site_transient_timeout_tag1', '1', 'no'),
					('rss_1', 'rss', 'yes')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a, b FROM wp_options a, wp_options b
			WHERE a.option_name LIKE '\_transient\_%'
			AND a.option_name NOT LIKE '\_transient\_timeout_%'
			AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			AND b.option_value < UNIX_TIMESTAMP()"
		);
		$this->assertParityRows( 'SELECT option_name FROM wp_options ORDER BY option_id' );
	}

	public function test_multi_table_delete_using_and_single_target_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'x'), (3, 'z'), (4, 'other')",
			)
		);

		$this->assertParityRowCount( 'DELETE FROM a, b USING t1 a, t2 b WHERE a.id = b.id AND a.id = 1' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );

		$this->assertParityRowCount( 'DELETE a FROM t1 a, t2 b WHERE a.id = b.id' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM t2 ORDER BY id' );
	}

	public function test_joined_delete_using_columns_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM t1 a
			JOIN t2 b USING (id)
			WHERE b.flag = 'drop'"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, flag FROM t2 ORDER BY id' );
	}

	public function test_cross_joined_delete_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)",
			)
		);

		$this->assertParityRowCount(
			'DELETE a FROM t1 a CROSS JOIN t2 b
			WHERE a.id = 2 AND b.id = 4'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE a, b FROM t1 a CROSS JOIN t2 b
			WHERE a.id = 1 AND b.id = 4'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE FROM a USING t1 a CROSS JOIN t2 b
			WHERE a.id = 3 AND b.id = 5'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_cross_joined_delete_on_predicates_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20), only_t1 INT)',
				'CREATE TABLE t2 (id INT, note VARCHAR(20), flag INT)',
				"INSERT INTO t1 VALUES (1, 'a1', 10), (2, 'a2', 20), (3, 'a3', 30)",
				"INSERT INTO t2 VALUES (1, 'b1', 1), (3, 'b3', 1), (4, 'b4', 1), (5, 'b5', 0)",
			)
		);

		$this->assertParityRowCount(
			'DELETE a FROM t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE b.flag = 1 AND a.id = 1'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE a, b FROM t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE a.id = 3'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount( "INSERT INTO t1 VALUES (6, 'a6', 60)" );
		$this->assertParityRowCount( "INSERT INTO t2 VALUES (6, 'b6', 1)" );
		$this->assertParityRowCount(
			'DELETE FROM a USING t1 a CROSS JOIN t2 b ON a.id = b.id
			WHERE b.id = 6'
		);
		$this->assertParityRows( 'SELECT id, note, only_t1 FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note, flag FROM t2 ORDER BY id' );
	}

	public function test_joined_delete_information_schema_source_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE info_delete_items (id INT, value VARCHAR(64))',
				"INSERT INTO info_delete_items VALUES
					(1, 'info_delete_items'),
					(2, 'other'),
					(3, 'info_delete_items'),
					(4, 'info_delete_items')",
			)
		);

		$this->assertParityRowCount(
			"DELETE d FROM info_delete_items d
			JOIN information_schema.tables it ON d.value = it.table_name
			WHERE it.table_schema = 'wp' AND d.id < 4"
		);
		$this->assertParityRows( 'SELECT id, value FROM info_delete_items ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE d FROM info_delete_items d, information_schema.tables it
			WHERE d.value = it.table_name AND it.table_schema = 'wp'"
		);
		$this->assertParityRows( 'SELECT id, value FROM info_delete_items ORDER BY id' );
	}

	public function test_joined_delete_target_wildcards_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wildcard_t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE wildcard_t2 (id INT, target_id INT, flag VARCHAR(20))',
				"INSERT INTO wildcard_t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')",
				"INSERT INTO wildcard_t2 VALUES (10, 1, 'drop'), (11, 3, 'drop'), (12, 4, 'keep')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a.* FROM wildcard_t1 a
			JOIN wildcard_t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop'"
		);
		$this->assertParityRows( 'SELECT id, note FROM wildcard_t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM wildcard_t2 ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE wildcard_multi_t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE wildcard_multi_t2 (id INT, target_id INT, flag VARCHAR(20))',
				"INSERT INTO wildcard_multi_t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO wildcard_multi_t2 VALUES (10, 1, 'drop'), (11, 3, 'drop'), (12, 2, 'keep')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a.*, b FROM wildcard_multi_t1 a
			JOIN wildcard_multi_t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop'"
		);
		$this->assertParityRows( 'SELECT id, note FROM wildcard_multi_t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM wildcard_multi_t2 ORDER BY id' );
	}

	public function test_joined_delete_derived_sources_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE derived_delete_t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE derived_delete_t2 (id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO derived_delete_t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO derived_delete_t2 VALUES (1, 'drop', 'x'), (3, 'drop', 'z'), (4, 'keep', 'other')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM derived_delete_t1 a
			JOIN (SELECT id FROM derived_delete_t2 WHERE flag = 'drop') b ON a.id = b.id"
		);
		$this->assertParityRows( 'SELECT id, note FROM derived_delete_t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, flag FROM derived_delete_t2 ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE derived_comma_t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE derived_comma_t2 (id INT, flag VARCHAR(20))',
				"INSERT INTO derived_comma_t1 VALUES (1, 'a'), (2, 'b'), (3, 'c')",
				"INSERT INTO derived_comma_t2 VALUES (1, 'drop'), (2, 'keep'), (3, 'drop')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM derived_comma_t1 a, (SELECT id FROM derived_comma_t2 WHERE flag = 'drop') b
			WHERE a.id = b.id"
		);
		$this->assertParityRows( 'SELECT id, note FROM derived_comma_t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, flag FROM derived_comma_t2 ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE derived_multi_t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE derived_multi_t2 (id INT, flag VARCHAR(20))',
				'CREATE TABLE derived_multi_t3 (id INT, note VARCHAR(20))',
				"INSERT INTO derived_multi_t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')",
				"INSERT INTO derived_multi_t2 VALUES (1, 'drop'), (3, 'drop'), (4, 'keep')",
				"INSERT INTO derived_multi_t3 VALUES (1, 'x'), (3, 'z'), (4, 'other')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a, c FROM derived_multi_t1 a
			JOIN derived_multi_t3 c ON c.id = a.id
			JOIN (SELECT id FROM derived_multi_t2 WHERE flag = 'drop') b ON b.id = a.id"
		);
		$this->assertParityRows( 'SELECT id, note FROM derived_multi_t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, flag FROM derived_multi_t2 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM derived_multi_t3 ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE derived_first_t2 (id INT, flag VARCHAR(20))',
				'CREATE TABLE derived_first_t3 (id INT, note VARCHAR(20))',
				"INSERT INTO derived_first_t2 VALUES (1, 'drop'), (2, 'keep'), (3, 'drop')",
				"INSERT INTO derived_first_t3 VALUES (1, 'x'), (2, 'y'), (3, 'z')",
			)
		);

		$this->assertParityRowCount(
			"DELETE c FROM (SELECT id FROM derived_first_t2 WHERE flag = 'drop') b
			JOIN derived_first_t3 c ON c.id = b.id"
		);
		$this->assertParityRows( 'SELECT id, flag FROM derived_first_t2 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, note FROM derived_first_t3 ORDER BY id' );
	}

	public function test_single_target_joined_delete_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f')",
				"INSERT INTO t2 VALUES
					(10, 1, 'drop', 'x'),
					(11, 1, 'drop', 'duplicate'),
					(12, 2, 'keep', 'y'),
					(13, 3, 'drop', 'z'),
					(14, 4, 'drop', 'w'),
					(15, 5, 'source', 's'),
					(16, 6, 'source', 'q')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE FROM a USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE a.id = 5 AND b.flag = 'source'"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE t1 FROM t1
			JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount( 'DELETE a FROM t1 a WHERE a.id = 6' );
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE child FROM t1 parent
			JOIN t1 child ON child.id = 5
			WHERE parent.id = 2'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );
	}

	public function test_multi_target_joined_delete_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t1 (id INT, note VARCHAR(20))',
				'CREATE TABLE t2 (id INT, target_id INT, flag VARCHAR(20), note VARCHAR(20))',
				"INSERT INTO t1 VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd'), (5, 'e'), (6, 'f')",
				"INSERT INTO t2 VALUES
					(10, 1, 'drop', 'x'),
					(11, 1, 'drop', 'duplicate'),
					(12, 2, 'keep', 'y'),
					(13, 3, 'drop', 'z'),
					(14, 4, 'drop', 'w'),
					(15, 5, 'same-table', 's'),
					(16, 6, 'keep', 'q')",
			)
		);

		$this->assertParityRowCount(
			"DELETE a, b FROM t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 1"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE FROM a, b USING t1 a
			JOIN t2 b ON b.target_id = a.id
			WHERE b.flag = 'drop' AND a.id = 3"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			"DELETE t1, t2 FROM t1
			INNER JOIN t2 ON t2.target_id = t1.id
			WHERE t2.flag = 'drop' AND t1.id = 4"
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );

		$this->assertParityRowCount(
			'DELETE parent, child FROM t1 parent
			JOIN t1 child ON child.id = 5
			WHERE parent.id = 2'
		);
		$this->assertParityRows( 'SELECT id, note FROM t1 ORDER BY id' );
		$this->assertParityRows( 'SELECT id, target_id, flag FROM t2 ORDER BY id' );
	}

	public function test_insert_set_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100), hits INTEGER DEFAULT 0)',
			)
		);

		$this->assertParityRowCount( "INSERT INTO items SET id = 1, name = 'first', hits = 2" );
		$this->assertParityRowCount( "INSERT items SET id = 2, name = 'second'" );
		$this->assertParityRowCount( "INSERT IGNORE items SET id = 2, name = 'duplicate'" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_insert_select_and_replace_select_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE source_items (id INTEGER, name VARCHAR(100))',
				'CREATE TABLE items (id INTEGER PRIMARY KEY, name VARCHAR(100))',
				"INSERT INTO source_items VALUES (1, 'first'), (2, 'second')",
			)
		);

		$this->assertParityRowCount( 'INSERT INTO items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertParityRowCount( 'INSERT INTO items SELECT id, name FROM source_items WHERE id = 2' );
		$this->assertParityRowCount( 'INSERT IGNORE items (id, name) SELECT id, name FROM source_items WHERE id = 1' );
		$this->assertParityRowCount( "INSERT items (id, name) SELECT 3, 'third' FROM DUAL WHERE (SELECT NULL FROM DUAL) IS NULL" );
		$this->assertParityRowCount( "REPLACE INTO items (id, name) SELECT 1, 'replaced' FROM DUAL" );
		$this->assertParityRows( 'SELECT id, name FROM items ORDER BY id' );
	}

	public function test_insert_select_text_and_blob_write_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE insert_select_write_coercions (id INTEGER PRIMARY KEY, text_value TEXT, blob_value BLOB)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 1, TRUE, TRUE' );
		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 2, FALSE, FALSE' );
		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 3, 0x62, 0x62' );
		$this->assertParityRowCount( "INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 4, x'63', x'63'" );
		$this->assertParityRowCount( "INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 5, b'01100100', b'01100100'" );
		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 6, 0b01100101, 0b01100101' );
		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 7, 123.456, 123.456' );
		$this->assertParityRowCount( 'INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 8, -7, -7' );
		$this->assertParityRowCount( "INSERT INTO insert_select_write_coercions (id, text_value, blob_value) SELECT 9, 'plain' AS text_alias, 'plain' AS blob_alias" );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM insert_select_write_coercions ORDER BY id' );
	}

	public function test_insert_select_text_blob_with_temporal_staging_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE insert_select_temporal_write_coercions (
					id INTEGER PRIMARY KEY,
					d DATE,
					text_value TEXT,
					blob_value BLOB
				)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO insert_select_temporal_write_coercions (id, d, text_value, blob_value)
			SELECT 1, '2025-01-01', TRUE, TRUE"
		);
		$this->assertParityRowCount(
			"INSERT INTO insert_select_temporal_write_coercions (id, d, text_value, blob_value)
			SELECT 2, '2025-01-02', 0x62, 0x62"
		);
		$this->assertParityRowCount(
			"INSERT INTO insert_select_temporal_write_coercions (id, d, text_value, blob_value)
			SELECT 3, '2025-01-03', x'63', x'63'"
		);
		$this->assertParityRows( 'SELECT id, d, text_value, blob_value FROM insert_select_temporal_write_coercions ORDER BY id' );
	}

	public function test_insert_ignore_select_text_and_blob_write_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE insert_ignore_select_write_coercions (id INTEGER PRIMARY KEY, text_value TEXT, blob_value BLOB)',
				"INSERT INTO insert_ignore_select_write_coercions VALUES (1, 'old', 'old')",
			)
		);

		$this->assertParityRowCount( 'INSERT IGNORE INTO insert_ignore_select_write_coercions (id, text_value, blob_value) SELECT 1, TRUE, TRUE' );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM insert_ignore_select_write_coercions ORDER BY id' );
		$this->assertParityRowCount( 'INSERT IGNORE INTO insert_ignore_select_write_coercions (id, text_value, blob_value) SELECT 2, TRUE, TRUE' );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM insert_ignore_select_write_coercions ORDER BY id' );
	}

	public function test_replace_select_text_and_blob_write_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_write_coercions (id INTEGER PRIMARY KEY, text_value TEXT, blob_value BLOB)',
				"INSERT INTO replace_select_write_coercions VALUES (1, 'old', 'old')",
			)
		);

		$this->assertParityRowCount( 'REPLACE INTO replace_select_write_coercions (id, text_value, blob_value) SELECT 1, TRUE, TRUE' );
		$this->assertParityRowCount( "REPLACE INTO replace_select_write_coercions (id, text_value, blob_value) SELECT 2, x'63', x'63'" );
		$this->assertParityRows( 'SELECT id, text_value, blob_value FROM replace_select_write_coercions ORDER BY id' );
	}

	public function test_replace_select_manual_conflict_text_and_blob_write_coercions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_manual_write_coercions (
					id INTEGER PRIMARY KEY,
					u INTEGER UNIQUE,
					text_value TEXT,
					blob_value BLOB
				)',
				"INSERT INTO replace_select_manual_write_coercions VALUES (1, 1, 'old', 'old')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO replace_select_manual_write_coercions (id, u, text_value, blob_value)
			SELECT 2, 1, TRUE, TRUE'
		);
		$this->assertParityRows( 'SELECT id, u, text_value, blob_value FROM replace_select_manual_write_coercions ORDER BY id' );
	}

	public function test_regexp_and_not_regexp_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE options (option_name VARCHAR(100))',
				"INSERT INTO options VALUES ('rss_123'), ('RSS_456'), ('transient'), ('alpha'), ('ALPS')",
			)
		);

		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name REGEXP '^rss_.+$' ORDER BY option_name" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name RLIKE '^rss_.+$' ORDER BY option_name" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name REGEXP BINARY '^rss_.+$' ORDER BY option_name" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name NOT REGEXP '^rss_.+$' ORDER BY lower(option_name), option_name DESC" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name NOT RLIKE BINARY '^RSS_.+$' ORDER BY lower(option_name), option_name DESC" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE BINARY option_name REGEXP '^a' ORDER BY lower(option_name), option_name DESC" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE BINARY option_name NOT REGEXP '^a' ORDER BY lower(option_name), option_name DESC" );
		$this->assertParityRows( "SELECT o.option_name FROM options o WHERE BINARY o.option_name RLIKE '^a' ORDER BY lower(o.option_name), o.option_name DESC" );
	}

	public function test_regexp_null_empty_pattern_and_null_haystack_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE wp_regexp_null_posts (
					ID BIGINT(20) UNSIGNED NOT NULL,
					post_title VARCHAR(255),
					PRIMARY KEY (ID)
				)',
				"INSERT INTO wp_regexp_null_posts (ID, post_title) VALUES
					(1, 'Apple'),
					(2, 'apple'),
					(3, ''),
					(4, NULL),
					(11, 'Banana'),
					(12, 'Alpha')",
			)
		);

		$previous_error_reporting = error_reporting( error_reporting() & ~E_WARNING & ~E_DEPRECATED );
		try {
			foreach (
				array(
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title RLIKE NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT REGEXP NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT RLIKE NULL ORDER BY ID',
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP '' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title RLIKE '' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT REGEXP '' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT RLIKE '' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP '^A' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT REGEXP '^A' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE BINARY post_title REGEXP '' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE BINARY post_title NOT REGEXP '^A' ORDER BY ID",
					"SELECT p.ID FROM wp_regexp_null_posts p WHERE BINARY p.post_title RLIKE '^A' ORDER BY p.ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP BINARY '^A' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP BINARY '^a' ORDER BY ID",
					"SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP BINARY '' ORDER BY ID",
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title REGEXP BINARY NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT REGEXP BINARY NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title RLIKE BINARY NULL ORDER BY ID',
					'SELECT ID FROM wp_regexp_null_posts WHERE post_title NOT RLIKE BINARY NULL ORDER BY ID',
				) as $sql
			) {
				$this->assertParityRows( $sql );
			}
		} finally {
			error_reporting( $previous_error_reporting );
		}
	}

	public function test_replace_values_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(100) NOT NULL DEFAULT '',
					hits INTEGER NOT NULL DEFAULT 0
				)",
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)",
			)
		);

		$this->assertParityRowCount( "REPLACE INTO items (id, name, hits) VALUES (1, 'new', 2)" );
		$this->assertParityRowCount( "REPLACE items (id, name) VALUES (2, 'second')" );
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_on_duplicate_key_update_values_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(100) UNIQUE,
					hits INTEGER NOT NULL DEFAULT 0
				)',
				"INSERT INTO items (id, name, hits) VALUES (1, 'old', 1)",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (1, 'renamed', 7)
			ON DUPLICATE KEY UPDATE name = VALUES(name), hits = VALUES(hits)"
		);
		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (2, 'renamed', 11)
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)"
		);
		$this->assertParityRowCount(
			"INSERT INTO items (id, name, hits) VALUES (3, 'third', 3)
			ON DUPLICATE KEY UPDATE hits = VALUES(hits)"
		);
		$this->assertParityRows( 'SELECT id, name, hits FROM items ORDER BY id' );
	}

	public function test_on_duplicate_key_update_multirow_term_relationships_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wptests_term_relationships (
					object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					term_taxonomy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
					term_order INT(11) NOT NULL DEFAULT '0',
					PRIMARY KEY (object_id, term_taxonomy_id),
					KEY term_taxonomy_id (term_taxonomy_id)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order)
				VALUES (1, 11, 0), (2, 11, 0)',
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO wptests_term_relationships (object_id, term_taxonomy_id, term_order)
			VALUES (1, 11, 7), (1, 12, 0), (3, 11, 0)
			ON DUPLICATE KEY UPDATE term_order = VALUES(term_order)'
		);
		$this->assertParityRows( 'SELECT object_id, term_taxonomy_id, term_order FROM wptests_term_relationships ORDER BY object_id, term_taxonomy_id' );
	}

	public function test_on_duplicate_key_update_serialized_nul_payload_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (option_id),
					UNIQUE KEY option_name (option_name)
				)",
			)
		);

		$payload = serialize(
			array(
				"\0*\0data" => "line\n<iframe class='youtube-player' rel=\"https://api.w.org/\">",
			)
		);
		$this->assertParityRowCount(
			"INSERT INTO wp_options (option_name, option_value, autoload)
			VALUES ('_transient_feed_mod_example', " . $this->mysql_single_quoted_literal( $payload ) . ", 'off')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name),
				option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);

		$updated_payload = serialize(
			array(
				"\0*\0data" => "line\n<iframe class='youtube-player' rel=\"https://api.w.org/\">",
				'updated'   => "tail\0value",
			)
		);
		$this->assertParityRowCount(
			"INSERT INTO wp_options (option_name, option_value, autoload)
			VALUES ('_transient_feed_mod_example', " . $this->mysql_single_quoted_literal( $updated_payload ) . ", 'off')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name),
				option_value = VALUES(option_value),
				autoload = VALUES(autoload)"
		);
		$this->assertParityRows( "SELECT option_name, option_value, autoload FROM wp_options WHERE option_name = '_transient_feed_mod_example'" );
	}

	public function test_on_duplicate_key_update_qualified_target_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE t (
					id INTEGER PRIMARY KEY,
					d VARCHAR(100) NOT NULL DEFAULT \'\'
				)',
				"INSERT INTO t (id, d) VALUES (1, 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO t (id, d) VALUES (1, 'new')
			ON DUPLICATE KEY UPDATE t.d = VALUES(d)"
		);
		$this->assertParityRows( 'SELECT id, d FROM t ORDER BY id' );
	}

	public function test_temporal_insert_values_and_set_writes_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_writes' );

		$this->assertParityRowCount(
			"INSERT INTO temporal_writes (id, d, tm, dt, ts, payload) VALUES
				(1, '2025-10-23', '18:30:00', '2025-10-23 18:30:00', '2025-10-23 18:30:00', 'canonical'),
				(2, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-23', '2025-10-23', 'normalized'),
				(3, NULL, NULL, NULL, NULL, 'nullable')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_writes SET
				id = 4,
				d = '2025-11-01 01:02:03.123456',
				tm = '18:30:00.123456',
				dt = '2025-11-01',
				ts = '2025-11-01 01:02:03.123456',
				payload = 'insert-set'"
		);
		$this->assertParityRowCount(
			"INSERT IGNORE INTO temporal_writes (id, d, tm, dt, ts, payload) VALUES
				(5, '2025-12-01 05:06:07.123456', '18:30:00.123456', '2025-12-01', '2025-12-01 05:06:07.123456', 'insert-ignore')"
		);

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_writes' ) );
	}

	public function test_temporal_rfc3339_z_writes_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_rfc3339_z' );

		$this->assertParityRowCount(
			"INSERT INTO temporal_rfc3339_z (id, d, tm, dt, ts, payload) VALUES
				(1, '2025-10-23T18:30:00Z', '18:30:00', '2025-10-23T18:30:00Z', '2025-10-23T18:30:00Z', 'insert-values')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_rfc3339_z SET
				id = 2,
				d = '2025-11-01T01:02:03Z',
				tm = '18:30:00',
				dt = '2025-11-01T01:02:03Z',
				ts = '2025-11-01T01:02:03Z',
				payload = 'insert-set'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_rfc3339_z
			SET d = '2025-12-01T05:06:07Z',
				dt = '2025-12-01T05:06:07Z',
				ts = '2025-12-01T05:06:07Z'
			WHERE id = 1"
		);

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_rfc3339_z' ) );
	}

	public function test_temporal_insert_select_explicit_columns_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_explicit' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_explicit_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_explicit_source VALUES
					(1, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-24', '2025-10-24 01:02:03.123456', 'explicit')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_explicit (payload, ts, dt, tm, d, id)
			SELECT payload, ts_text, dt_text, tm_text, d_text, id
			FROM temporal_insert_select_explicit_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_explicit' ) );
	}

	public function test_temporal_insert_select_implicit_columns_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_implicit' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_implicit_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_implicit_source VALUES
					(1, '2025-11-01 05:06:07.123456', '18:31:32.654321', '2025-11-02', '2025-11-03 04:05:06.654321', 'implicit')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_implicit
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_insert_select_implicit_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_implicit' ) );
	}

	public function test_temporal_insert_select_strict_errors_leave_rows(): void {
		$this->create_temporal_write_table( 'temporal_insert_select_strict' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_insert_select_strict (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'stable')",
				'CREATE TABLE temporal_insert_select_strict_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_strict_source VALUES
					(2, 'bad', '2025-01-02', 'plain'),
					(3, '2025-01-03', 'bad', 'ignore')",
			)
		);

		$stable_selects = array(
			$this->temporal_select_sql( 'temporal_insert_select_strict' ),
			'SELECT id, d_text, dt_text, payload FROM temporal_insert_select_strict_source ORDER BY id',
		);

		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_insert_select_strict (id, d, dt, payload)
			SELECT id, d_text, dt_text, payload
			FROM temporal_insert_select_strict_source
			WHERE payload = 'plain'",
			"Incorrect date value: 'bad'",
			$stable_selects
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT IGNORE INTO temporal_insert_select_strict (id, d, dt, payload)
			SELECT id, d_text, dt_text, payload
			FROM temporal_insert_select_strict_source
			WHERE payload = 'ignore'",
			"Incorrect datetime value: 'bad'",
			$stable_selects
		);
	}

	public function test_temporal_insert_select_non_strict_defaults_and_nulls_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_insert_select_non_strict', 'NOT NULL' );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_insert_select_non_strict_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_select_non_strict_source VALUES
					(1, 'bad', 'bad', 'bad', 'invalid'),
					(2, NULL, NULL, NULL, 'selected-nulls')",
			)
		);

		$this->assertParityRowCount(
			'INSERT INTO temporal_insert_select_non_strict (id, d, dt, ts, payload)
			SELECT id, d_text, dt_text, ts_text, payload
			FROM temporal_insert_select_non_strict_source
			ORDER BY id'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_select_non_strict' ) );
	}

	public function test_temporal_insert_ignore_select_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_insert_ignore_select' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_insert_ignore_select (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'stable')",
				'CREATE TABLE temporal_insert_ignore_select_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_insert_ignore_select_source VALUES
					(1, '2025-02-01 01:02:03', '18:31:00.123456', '2025-02-01', '2025-02-01 01:02:03.123456', 'ignored'),
					(2, '2025-03-01 01:02:03', '18:32:00.123456', '2025-03-01', '2025-03-01 01:02:03.123456', 'inserted')",
			)
		);

		$this->assertParityRowCount(
			'INSERT IGNORE INTO temporal_insert_ignore_select (id, d, tm, dt, ts, payload)
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_insert_ignore_select_source
			ORDER BY id'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_insert_ignore_select' ) );
	}

	public function test_temporal_replace_select_native_conflict_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_native (
					id INT,
					d DATE UNIQUE,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_native (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'old')",
				'CREATE TABLE temporal_replace_select_native_source (
					id INT,
					d_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_native_source VALUES
					(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'new')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_native (id, d, dt, ts, payload)
			SELECT id, d_text, dt_text, ts_text, payload
			FROM temporal_replace_select_native_source'
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_replace_select_native ORDER BY id' );
	}

	public function test_temporal_replace_select_non_strict_selected_nulls_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_replace_select_non_strict_nulls', 'NOT NULL' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_replace_select_non_strict_nulls (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-10-23', '18:30:00', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'old')",
				'CREATE TABLE temporal_replace_select_non_strict_nulls_source (
					id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_non_strict_nulls_source VALUES
					(1, NULL, NULL, NULL, NULL, 'selected-nulls')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_non_strict_nulls (id, d, tm, dt, ts, payload)
			SELECT id, d_text, tm_text, dt_text, ts_text, payload
			FROM temporal_replace_select_non_strict_nulls_source'
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_replace_select_non_strict_nulls' ) );
	}

	public function test_temporal_replace_select_strict_error_preserves_target(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_error (
					id INT PRIMARY KEY,
					d DATE,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_error (id, d, payload) VALUES
					(1, '2025-10-23', 'stable')",
				'CREATE TABLE temporal_replace_select_error_source (
					id INT,
					d_text VARCHAR(40),
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_select_error_source VALUES
					(1, 'bad', 'stable')",
			)
		);

		$this->assert_temporal_error_leaves_rows(
			'REPLACE INTO temporal_replace_select_error (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_replace_select_error_source',
			"Incorrect date value: 'bad'",
			array(
				'SELECT id, d, payload FROM temporal_replace_select_error ORDER BY id',
				'SELECT id, d_text, payload FROM temporal_replace_select_error_source ORDER BY id',
			)
		);
	}

	public function test_replace_select_multiple_unique_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_multi_unique (
					id INT PRIMARY KEY,
					email VARCHAR(40) UNIQUE,
					slug VARCHAR(40) UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_multi_unique VALUES
					(1, 'one@example.test', 'one', 'old-id'),
					(2, 'two@example.test', 'two', 'old-email')",
				'CREATE TABLE replace_select_multi_unique_source (
					id INT,
					email VARCHAR(40),
					slug VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_multi_unique_source VALUES
					(1, 'two@example.test', 'incoming', 'new')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO replace_select_multi_unique (id, email, slug, payload)
			SELECT id, email, slug, payload
			FROM replace_select_multi_unique_source'
		);
		$this->assertParityRows( 'SELECT id, email, slug, payload FROM replace_select_multi_unique ORDER BY id' );
	}

	public function test_replace_select_omitted_auto_increment_primary_key_with_secondary_unique_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_omitted_ai_pk (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					email VARCHAR(40) NOT NULL,
					payload VARCHAR(20),
					UNIQUE KEY email_unique (email)
				)',
				"INSERT INTO replace_select_omitted_ai_pk (email, payload) VALUES
					('one@example.test', 'old-one'),
					('two@example.test', 'old-two')",
				'CREATE TABLE replace_select_omitted_ai_pk_source (
					email VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_omitted_ai_pk_source VALUES
					('two@example.test', 'incoming')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO replace_select_omitted_ai_pk (email, payload)
			SELECT email, payload
			FROM replace_select_omitted_ai_pk_source'
		);
		$this->assertParityRows( 'SELECT id, email, payload FROM replace_select_omitted_ai_pk ORDER BY id' );
	}

	public function test_replace_select_case_insensitive_unique_key_stores_incoming_value(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_ci_unique (
					name VARCHAR(20),
					payload VARCHAR(20),
					UNIQUE KEY name_unique (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO replace_select_ci_unique VALUES ('First', 'old')",
				'CREATE TABLE replace_select_ci_unique_source (
					name VARCHAR(20),
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_ci_unique_source VALUES ('first', 'incoming')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO replace_select_ci_unique (name, payload)
			SELECT name, payload
			FROM replace_select_ci_unique_source'
		);
		$this->assertParityRows( 'SELECT name, payload FROM replace_select_ci_unique ORDER BY name' );
	}

	public function test_temporal_replace_select_multiple_unique_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_multi_unique (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					slug VARCHAR(40) UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_replace_select_multi_unique VALUES
					(1, '2025-10-23', 'one', 'old-id'),
					(2, '2025-10-24', 'two', 'old-date')",
				'CREATE TABLE temporal_replace_select_multi_unique_source (
					id INT,
					d_text VARCHAR(40),
					slug VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_replace_select_multi_unique_source VALUES
					(1, '2025-10-24 01:02:03', 'incoming', 'new')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_multi_unique (id, d, slug, payload)
			SELECT id, d_text, slug, payload
			FROM temporal_replace_select_multi_unique_source'
		);
		$this->assertParityRows( 'SELECT id, d, slug, payload FROM temporal_replace_select_multi_unique ORDER BY id' );
	}

	public function test_temporal_replace_select_case_insensitive_unique_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_ci_unique (
					name VARCHAR(20),
					d DATE,
					payload VARCHAR(20),
					UNIQUE KEY name_unique (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"INSERT INTO temporal_replace_select_ci_unique VALUES ('First', '2025-10-23', 'old')",
				'CREATE TABLE temporal_replace_select_ci_unique_source (
					name VARCHAR(20),
					d_text VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_replace_select_ci_unique_source VALUES
					('first', '2025-10-24 01:02:03', 'incoming')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO temporal_replace_select_ci_unique (name, d, payload)
			SELECT name, d_text, payload
			FROM temporal_replace_select_ci_unique_source'
		);
		$this->assertParityRows( 'SELECT name, d, payload FROM temporal_replace_select_ci_unique ORDER BY name' );
	}

	public function test_replace_select_nullable_unique_nulls_do_not_conflict(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE replace_select_nullable_unique (
					id INT PRIMARY KEY,
					email VARCHAR(40) UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_nullable_unique VALUES
					(1, NULL, 'old-null'),
					(2, 'two@example.test', 'old-two')",
				'CREATE TABLE replace_select_nullable_unique_source (
					id INT,
					email VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_nullable_unique_source VALUES
					(3, NULL, 'incoming-null')",
			)
		);

		$this->assertParityRowCount(
			'REPLACE INTO replace_select_nullable_unique (id, email, payload)
			SELECT id, email, payload
			FROM replace_select_nullable_unique_source'
		);
		$this->assertParityRows( 'SELECT id, email, payload FROM replace_select_nullable_unique ORDER BY id' );
	}

	public function test_temporal_replace_select_manual_strict_error_preserves_target(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_select_manual_error (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_replace_select_manual_error VALUES
					(1, '2025-10-23', 'old-id'),
					(2, '2025-10-24', 'old-date')",
				'CREATE TABLE temporal_replace_select_manual_error_source (
					id INT,
					d_text VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_replace_select_manual_error_source VALUES
					(1, 'bad', 'incoming')",
			)
		);

		$this->assert_temporal_error_leaves_rows(
			'REPLACE INTO temporal_replace_select_manual_error (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_replace_select_manual_error_source',
			"Incorrect date value: 'bad'",
			array(
				'SELECT id, d, payload FROM temporal_replace_select_manual_error ORDER BY id',
				'SELECT id, d_text, payload FROM temporal_replace_select_manual_error_source ORDER BY id',
			)
		);
	}

	public function test_replace_select_rejects_incoming_duplicate_unique_groups_without_mutation(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		foreach (
			array(
				'CREATE TABLE replace_select_duplicate_incoming (
					id INT PRIMARY KEY,
					email VARCHAR(40) UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_duplicate_incoming VALUES
					(1, 'one@example.test', 'old-one'),
					(2, 'two@example.test', 'old-two')",
				'CREATE TABLE replace_select_duplicate_incoming_source (
					id INT,
					email VARCHAR(40),
					payload VARCHAR(20)
				)',
				"INSERT INTO replace_select_duplicate_incoming_source VALUES
					(3, 'dup@example.test', 'incoming-a'),
					(4, 'dup@example.test', 'incoming-b')",
			) as $query
		) {
			$driver->query( $query );
		}

		$this->assert_duckdb_error_contains(
			$driver,
			'REPLACE INTO replace_select_duplicate_incoming (id, email, payload)
			SELECT id, email, payload
			FROM replace_select_duplicate_incoming_source',
			'Incoming rows contain duplicate values for a unique key'
		);
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'email'   => 'one@example.test',
					'payload' => 'old-one',
				),
				array(
					'id'      => 2,
					'email'   => 'two@example.test',
					'payload' => 'old-two',
				),
			),
			$driver->query(
				'SELECT id, email, payload
				FROM replace_select_duplicate_incoming
				ORDER BY id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assert_no_select_write_stage_tables( $driver );
	}

	public function test_temporal_select_write_stage_tables_are_cleaned_up(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( 'CREATE TABLE temporal_stage_cleanup (id INT PRIMARY KEY, d DATE NULL, payload VARCHAR(20))' );
		$driver->query( 'CREATE TABLE temporal_stage_cleanup_source (id INT, d_text VARCHAR(40), payload VARCHAR(20))' );
		$driver->query(
			"INSERT INTO temporal_stage_cleanup_source VALUES
				(1, '2025-01-01', 'valid'),
				(2, 'bad', 'invalid')"
		);

		$driver->query(
			"INSERT INTO temporal_stage_cleanup (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_stage_cleanup_source
			WHERE payload = 'valid'"
		);
		$this->assert_no_select_write_stage_tables( $driver );

		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_stage_cleanup (id, d, payload)
			SELECT id, d_text, payload
			FROM temporal_stage_cleanup_source
			WHERE payload = 'invalid'",
			"Incorrect date value: 'bad'"
		);
		$this->assert_no_select_write_stage_tables( $driver );

		$driver->query(
			"REPLACE INTO temporal_stage_cleanup (id, d, payload)
			SELECT 1, '2025-02-02', 'replaced'"
		);
		$this->assert_no_select_write_stage_tables( $driver );
	}

	public function test_temporal_strict_write_errors_match_sqlite(): void {
		$this->create_temporal_write_table(
			'temporal_strict',
			'NULL',
			array(
				'UNIQUE KEY payload_unique (payload)',
			)
		);
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_strict (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01 12:00:00', '2025-01-01 12:00:00', 'original')",
				'CREATE TABLE temporal_strict_source (id INT, dt_text VARCHAR(40), ts_text VARCHAR(40))',
				"INSERT INTO temporal_strict_source VALUES (1, 'bad', 'bad')",
			)
		);

		$stable_selects = array(
			$this->temporal_select_sql( 'temporal_strict' ),
			'SELECT id, dt_text, ts_text FROM temporal_strict_source ORDER BY id',
		);

		foreach (
			array(
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'insert-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, TRUE, 'insert-true-date')",
					'needle' => "Incorrect date value: '1'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, dt, payload) VALUES (2, 0, 'insert-zero-datetime')",
					'needle' => "Incorrect datetime value: '0'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, ts, payload) VALUES (2, TRUE, 'insert-true-timestamp')",
					'needle' => "Incorrect timestamp value: '1'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, '0000-00-00', 'insert-zero-date')",
					'needle' => "Incorrect date value: '0000-00-00'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, dt, payload) VALUES (2, '0000-00-00 00:00:00', 'insert-zero-datetime')",
					'needle' => "Incorrect datetime value: '0000-00-00 00:00:00'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, ts, payload) VALUES (2, '2020-01-00 00:00:00', 'insert-zero-in-timestamp')",
					'needle' => "Incorrect timestamp value: '2020-01-00 00:00:00'",
				),
				array(
					'sql'    => "INSERT IGNORE INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'ignore-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict SET id = 2, dt = 'bad', payload = 'set-bad-datetime'",
					'needle' => "Incorrect datetime value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict SET id = 2, d = '0000-00-00', payload = 'set-zero-date'",
					'needle' => "Incorrect date value: '0000-00-00'",
				),
				array(
					'sql'    => "REPLACE INTO temporal_strict (id, d, payload) VALUES (1, 'bad', 'replace-bad-date')",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "UPDATE temporal_strict SET ts = 'bad' WHERE id = 1",
					'needle' => "Incorrect timestamp value: 'bad'",
				),
				array(
					'sql'    => 'UPDATE temporal_strict t
						JOIN temporal_strict_source s ON s.id = t.id
						SET t.dt = s.dt_text
						WHERE t.id = 1',
					'needle' => "Incorrect datetime value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, payload) VALUES (2, 'bad', 'odku-insert')
						ON DUPLICATE KEY UPDATE payload = 'unexpected'",
					'needle' => "Incorrect date value: 'bad'",
				),
				array(
					'sql'    => "INSERT INTO temporal_strict (id, d, dt, payload) VALUES (2, '2025-01-02', '2025-01-02', 'original')
						ON DUPLICATE KEY UPDATE dt = 'bad'",
					'needle' => "Incorrect datetime value: 'bad'",
				),
			) as $case
		) {
			$this->assert_temporal_error_leaves_rows( $case['sql'], $case['needle'], $stable_selects );
		}
	}

	public function test_temporal_zero_date_sql_modes_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_modes_default' );
		$this->runParitySetup(
			array(
				"INSERT INTO temporal_modes_default (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'stable')",
			)
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_modes_default (id, d, payload) VALUES (2, '0000-00-00', 'default-zero-date')",
			"Incorrect date value: '0000-00-00'",
			array( $this->temporal_select_sql( 'temporal_modes_default' ) )
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_modes_default SET dt = '2020-00-15 00:00:00' WHERE id = 1",
			"Incorrect datetime value: '2020-00-15 00:00:00'",
			array( $this->temporal_select_sql( 'temporal_modes_default' ) )
		);

		$this->assertParityRowCount( "SET sql_mode = 'STRICT_TRANS_TABLES'" );
		$this->create_temporal_write_table( 'temporal_modes_strict' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_strict (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '0000-00-00 00:00:00', '2020-01-00 00:00:00', 'strict-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'strict-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_strict
			SET d = '2020-00-15', dt = '2020-01-00 00:00:00', ts = '0000-00-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_strict' ) );

		$this->assertParityRowCount( "SET sql_mode = 'NO_ZERO_DATE'" );
		$this->create_temporal_write_table( 'temporal_modes_no_zero_date' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_no_zero_date (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '0000-00-00 00:00:00', '0000-00-00 00:00:00', 'no-zero-date-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'no-zero-date-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_no_zero_date
			SET d = '0000-00-00', dt = '0000-00-00 00:00:00', ts = '0000-00-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_no_zero_date' ) );

		$this->assertParityRowCount( "SET sql_mode = 'NO_ZERO_IN_DATE'" );
		$this->create_temporal_write_table( 'temporal_modes_no_zero_in_date' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_no_zero_in_date (id, d, tm, dt, ts, payload) VALUES
				(1, '2020-00-15', '18:30:00', '2020-01-00 00:00:00', '2020-00-15 00:00:00', 'no-zero-in-date-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'no-zero-in-date-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_no_zero_in_date
			SET d = '2020-01-00', dt = '2020-00-15 00:00:00', ts = '2020-01-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_no_zero_in_date' ) );

		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_modes_empty' );
		$this->assertParityRowCount(
			"INSERT INTO temporal_modes_empty (id, d, tm, dt, ts, payload) VALUES
				(1, '0000-00-00', '18:30:00', '2020-00-15 00:00:00', '2020-01-00 00:00:00', 'empty-mode-insert'),
				(2, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'empty-mode-update')"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_modes_empty
			SET d = '2020-00-15', dt = '0000-00-00 00:00:00', ts = '2020-01-00 00:00:00'
			WHERE id = 2"
		);
		$this->assertParityRows( $this->temporal_select_sql( 'temporal_modes_empty' ) );
	}

	public function test_temporal_comma_space_sql_mode_rejects_zero_dates_in_duckdb(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES, NO_ZERO_DATE, NO_ZERO_IN_DATE'" );
		$row = $driver->query( 'SELECT @@SESSION.sql_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE', $row['@@SESSION.sql_mode'] );

		$this->create_duckdb_temporal_mode_table( $driver, 'temporal_comma_space_modes' );
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_comma_space_modes (id, d, payload) VALUES (1, '0000-00-00', 'zero-date')",
			"Incorrect date value: '0000-00-00'"
		);
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_comma_space_modes (id, dt, payload) VALUES (1, '2020-00-15 00:00:00', 'zero-in-date')",
			"Incorrect datetime value: '2020-00-15 00:00:00'"
		);

		$row = $driver->query( 'SELECT COUNT(*) AS row_count FROM temporal_comma_space_modes' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $row['row_count'] );
	}

	public function test_temporal_traditional_sql_mode_rejects_zero_dates_in_duckdb(): void {
		$driver = new WP_DuckDB_Driver(
			array(
				'path'     => ':memory:',
				'database' => 'wp',
			)
		);

		$driver->query( "SET SESSION sql_mode = 'TRADITIONAL'" );
		$row = $driver->query( 'SELECT @@SESSION.sql_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertStringContainsString( 'STRICT_TRANS_TABLES', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'STRICT_ALL_TABLES', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'NO_ZERO_DATE', $row['@@SESSION.sql_mode'] );
		$this->assertStringContainsString( 'NO_ZERO_IN_DATE', $row['@@SESSION.sql_mode'] );

		$this->create_duckdb_temporal_mode_table( $driver, 'temporal_traditional_mode' );
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_traditional_mode (id, d, payload) VALUES (1, '0000-00-00', 'zero-date')",
			"Incorrect date value: '0000-00-00'"
		);
		$this->assert_duckdb_error_contains(
			$driver,
			"INSERT INTO temporal_traditional_mode (id, ts, payload) VALUES (1, '2020-01-00 00:00:00', 'zero-in-date')",
			"Incorrect timestamp value: '2020-01-00 00:00:00'"
		);

		$row = $driver->query( 'SELECT COUNT(*) AS row_count FROM temporal_traditional_mode' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 0, (int) $row['row_count'] );
	}

	public function test_temporal_non_strict_implicit_defaults_match_sqlite(): void {
		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->create_temporal_write_table(
			'temporal_non_strict',
			'NOT NULL',
			array(
				'UNIQUE KEY payload_unique (payload)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(1, 'bad', '18:30:00', 'bad', 'bad', 'invalid-strings'),
				(2, TRUE, '18:30:00.123456', FALSE, 0, 'scalars')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict SET
				id = 3,
				d = 'bad',
				tm = '18:30:00',
				dt = FALSE,
				ts = TRUE,
				payload = 'insert-set'"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(4, '2025-02-01', '18:30:00', '2025-02-01', '2025-02-01', 'normal-update')"
		);
		$this->assertParityRowCount( 'UPDATE temporal_non_strict SET d = NULL, dt = NULL, ts = NULL WHERE id = 4' );

		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(5, '2025-03-01', '18:30:00', '2025-03-01', '2025-03-01', 'odku-target')"
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(6, '2025-03-02', '18:30:00', '2025-03-02', '2025-03-02', 'odku-target')
			ON DUPLICATE KEY UPDATE d = 'bad', dt = TRUE, ts = 0"
		);
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_non_strict (id, d, tm, dt, ts, payload) VALUES
				(7, '2025-03-03', '18:30:00', '2025-03-03', '2025-03-03', 'odku-target')
			ON DUPLICATE KEY UPDATE d = NULL",
			'NOT NULL',
			array( $this->temporal_select_sql( 'temporal_non_strict' ) )
		);

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_non_strict' ) );
	}

	public function test_temporal_non_strict_omitted_defaults_for_insert_values_and_set_match_sqlite(): void {
		$this->create_temporal_write_table( 'temporal_omitted_defaults', 'NOT NULL' );

		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults (id, payload) VALUES (1, 'values')" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults SET id = 2, payload = 'set'" );
		$this->assertParityRowCount( "INSERT INTO temporal_omitted_defaults (id, d, payload) VALUES (3, '2025-01-01', 'partial')" );

		$this->assertParityRows( $this->temporal_select_sql( 'temporal_omitted_defaults' ) );
	}

	public function test_temporal_strict_omitted_defaults_still_fail_at_insert_time(): void {
		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->create_temporal_write_table( 'temporal_omitted_strict_values', 'NOT NULL' );
		$this->create_temporal_write_table( 'temporal_omitted_strict_set', 'NOT NULL' );

		$this->assertParityRowCount( "SET sql_mode = 'STRICT_TRANS_TABLES'" );
		$this->assertParityErrorContains(
			'INSERT INTO temporal_omitted_strict_values (id) VALUES (1)',
			'NOT NULL'
		);
		$this->assertParityErrorContains(
			'INSERT INTO temporal_omitted_strict_set SET id = 1',
			'NOT NULL'
		);
	}

	public function test_temporal_full_column_insert_order_is_unchanged_by_omitted_defaults(): void {
		$this->assertParityRowCount( "SET sql_mode = ''" );
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_omitted_full_order (
					id INT,
					d DATE NOT NULL
				)',
			)
		);

		$this->assertParityRowCount( "INSERT INTO temporal_omitted_full_order VALUES (1, '2025-01-01')" );
		$this->assertParityRows( 'SELECT id, d FROM temporal_omitted_full_order ORDER BY id' );
	}

	public function test_temporal_replace_and_odku_conflict_values_are_coerced(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_conflict (
					id INT,
					d DATE NOT NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40),
					UNIQUE KEY d_unique (d)
				)',
				"INSERT INTO temporal_replace_conflict (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'original')",
			)
		);
		$this->assertParityRowCount(
			"REPLACE INTO temporal_replace_conflict (id, d, dt, ts, payload) VALUES
				(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'replaced')"
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_replace_conflict ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_error (
					id INT,
					d DATE NOT NULL,
					payload VARCHAR(40) UNIQUE
				)',
				"INSERT INTO temporal_replace_error (id, d, payload) VALUES (1, '2025-10-23', 'stable')",
			)
		);
		$this->assert_temporal_error_leaves_rows(
			"REPLACE INTO temporal_replace_error (id, d, payload) VALUES (2, 'bad', 'stable')",
			"Incorrect date value: 'bad'",
			array( 'SELECT id, d, payload FROM temporal_replace_error ORDER BY id' )
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_odku_conflict (
					id INT PRIMARY KEY,
					d DATE NOT NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40),
					UNIQUE KEY d_unique (d)
				)',
				"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
					(1, '2025-10-23', '2025-10-23 09:00:00', '2025-10-23 09:00:00', 'original')",
			)
		);
		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
				(2, '2025-10-23 18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'incoming')
			ON DUPLICATE KEY UPDATE d = VALUES(d), dt = VALUES(dt), ts = VALUES(ts), payload = 'updated'"
		);
		$this->assertParityRows( 'SELECT id, d, dt, ts, payload FROM temporal_odku_conflict ORDER BY id' );
		$this->assert_temporal_error_leaves_rows(
			"INSERT INTO temporal_odku_conflict (id, d, dt, ts, payload) VALUES
				(3, '2025-10-23', '2025-10-26', '2025-10-26', 'bad-update')
			ON DUPLICATE KEY UPDATE ts = 'bad'",
			"Incorrect timestamp value: 'bad'",
			array( 'SELECT id, d, dt, ts, payload FROM temporal_odku_conflict ORDER BY id' )
		);
	}

	public function test_temporal_odku_date_unique_conflict_uses_coerced_insert_value(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_odku_date_conflict (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_odku_date_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_date_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_odku_date_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_odku_date_unique_conflict_uses_coerced_insert_value(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_odku_date_conflict (
					id INT,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO tmp_temporal_odku_date_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO tmp_temporal_odku_date_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_odku_date_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_odku_prefers_secondary_unique_conflict_over_nonconflicting_primary(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_odku_secondary_conflict (
					id INT PRIMARY KEY,
					d DATE UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO tmp_temporal_odku_secondary_conflict (id, d, payload) VALUES (1, '2025-10-23', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO tmp_temporal_odku_secondary_conflict (id, d, payload) VALUES (2, '2025-10-23 18:30:00', 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_odku_secondary_conflict ORDER BY id' );
	}

	public function test_temporal_odku_omitted_default_unique_conflict_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"SET sql_mode = ''",
				'CREATE TABLE temporal_odku_omitted_conflict (
					id INT PRIMARY KEY,
					d DATE NOT NULL UNIQUE,
					payload VARCHAR(20)
				)',
				"INSERT INTO temporal_odku_omitted_conflict (id, d, payload) VALUES (1, '0000-00-00', 'old')",
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO temporal_odku_omitted_conflict (id, payload) VALUES (2, 'new')
			ON DUPLICATE KEY UPDATE payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_odku_omitted_conflict ORDER BY id' );
	}

	public function test_temporal_replace_manual_conflicts_use_storage_coerced_values(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_manual_conflict (
					id INT UNIQUE,
					d DATE UNIQUE,
					payload VARCHAR(40)
				)',
				"INSERT INTO temporal_replace_manual_conflict (id, d, payload) VALUES
					(1, '2025-10-23', 'old-date'),
					(2, '2025-10-24', 'old-id')",
			)
		);

		$this->assertParityRowCount(
			"REPLACE INTO temporal_replace_manual_conflict (id, d, payload) VALUES
				(2, '2025-10-23 18:30:00', 'new')"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM temporal_replace_manual_conflict ORDER BY id' );
	}

	public function test_temporary_temporal_replace_manual_conflicts_use_storage_coerced_values(): void {
		$this->runParitySetup(
			array(
				'CREATE TEMPORARY TABLE tmp_temporal_replace_manual_conflict (
					id INT UNIQUE,
					d DATE UNIQUE,
					payload VARCHAR(40)
				)',
				"INSERT INTO tmp_temporal_replace_manual_conflict (id, d, payload) VALUES
					(1, '2025-10-23', 'old-date'),
					(2, '2025-10-24', 'old-id')",
			)
		);

		$this->assertParityRowCount(
			"REPLACE INTO tmp_temporal_replace_manual_conflict (id, d, payload) VALUES
				(2, '2025-10-23 18:30:00', 'new')"
		);
		$this->assertParityRows( 'SELECT id, d, payload FROM tmp_temporal_replace_manual_conflict ORDER BY id' );
	}

	public function test_temporal_replace_manual_strict_error_preserves_caller_transaction(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_replace_manual_tx (
					id INT UNIQUE,
					d DATE,
					payload VARCHAR(40),
					UNIQUE KEY payload_key (payload)
				)',
				"INSERT INTO temporal_replace_manual_tx (id, d, payload) VALUES (1, '2025-10-23', 'old')",
				'START TRANSACTION',
			)
		);

		$this->assert_temporal_error_leaves_rows(
			"REPLACE INTO temporal_replace_manual_tx (id, d, payload) VALUES (1, 'not-a-date', 'new')",
			"Incorrect date value: 'not-a-date'",
			array( 'SELECT id, d, payload FROM temporal_replace_manual_tx ORDER BY id' )
		);
		$this->runParitySetup( array( 'ROLLBACK' ) );
	}

	public function test_temporal_joined_update_writes_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE temporal_join_posts (
					id INT,
					d DATE NULL,
					tm TIME NULL,
					dt DATETIME NULL,
					ts TIMESTAMP NULL,
					payload VARCHAR(40)
				)',
				'CREATE TABLE temporal_join_updates (
					post_id INT,
					d_text VARCHAR(40),
					tm_text VARCHAR(40),
					dt_text VARCHAR(40),
					ts_text VARCHAR(40),
					flag VARCHAR(20)
				)',
				"INSERT INTO temporal_join_posts (id, d, tm, dt, ts, payload) VALUES
					(1, '2025-01-01', '18:30:00', '2025-01-01', '2025-01-01', 'target-first-old'),
					(2, '2025-01-02', '18:30:00', '2025-01-02', '2025-01-02', 'comma-old'),
					(3, '2025-01-03', '18:30:00', '2025-01-03', '2025-01-03', 'non-first-old'),
					(4, '2025-01-04', '18:30:00', '2025-01-04', '2025-01-04', 'invalid-old'),
					(5, '2025-01-05', '18:30:00', '2025-01-05', '2025-01-05', 'derived-old')",
				"INSERT INTO temporal_join_updates VALUES
					(1, '2025-10-23 18:30:00.123456', '18:30:00.123456', '2025-10-23', '2025-10-23 18:30:00.123456', 'target-first'),
					(2, '2025-10-24', '18:30:00', '2025-10-24', '2025-10-24 01:02:03.123456', 'comma'),
					(3, '2025-10-25 09:08:07.123456', '18:30:00', '2025-10-25', '2025-10-25', 'non-first'),
					(4, 'bad', '18:30:00', 'bad', 'bad', 'invalid'),
					(5, '2025-10-26', '18:30:00', '2025-10-26 04:05:06.123456', '2025-10-26', 'derived')",
			)
		);

		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.d = u.d_text, p.tm = u.tm_text, p.dt = u.dt_text, p.ts = u.ts_text, p.payload = 'target-first'
			WHERE u.flag = 'target-first'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p, temporal_join_updates u
			SET p.dt = u.dt_text, p.ts = u.ts_text, p.payload = 'comma'
			WHERE p.id = u.post_id AND u.flag = 'comma'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_updates u
			JOIN temporal_join_posts p ON p.id = u.post_id
			SET p.d = u.d_text, p.payload = 'non-first'
			WHERE u.flag = 'non-first'"
		);
		$this->assertParityRowCount(
			"UPDATE temporal_join_posts p
			JOIN (
				SELECT post_id, dt_text
				FROM temporal_join_updates
				WHERE flag = 'derived'
			) u ON u.post_id = p.id
			SET p.dt = u.dt_text, p.payload = 'derived'"
		);
		$this->assertParityRows( $this->temporal_join_posts_select_sql() );

		$stable_selects = array(
			$this->temporal_join_posts_select_sql(),
			'SELECT post_id, d_text, tm_text, dt_text, ts_text, flag FROM temporal_join_updates ORDER BY post_id',
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.dt = u.dt_text
			WHERE u.flag = 'invalid'",
			"Incorrect datetime value: 'bad'",
			$stable_selects
		);
		$this->assert_temporal_error_leaves_rows(
			"UPDATE temporal_join_posts p
			JOIN temporal_join_updates u ON u.post_id = p.id
			SET p.dt = '2025-12-01', u.dt_text = '2025-12-01'
			WHERE p.id = 1",
			'UPDATE statement modifying multiple tables',
			$stable_selects
		);
	}

	public function test_case_insensitive_unique_conflicts_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE ci_items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY name (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO ci_items (id, name, payload) VALUES (1, 'first', 'a')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount( "INSERT IGNORE INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'ignored')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount(
			"INSERT INTO ci_items (id, name, payload) VALUES (2, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE name = VALUES(name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO ci_items (id, name, payload) VALUES (2, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM ci_items ORDER BY id' );
	}

	public function test_temporary_case_insensitive_unique_conflicts_use_temp_metadata(): void {
		$this->runParitySetup(
			array(
				"CREATE TEMPORARY TABLE tmp_ci_items (
					id INTEGER PRIMARY KEY,
					name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY name (name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO tmp_ci_items (id, name, payload) VALUES (1, 'first', 'a')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO tmp_ci_items (id, name, payload) VALUES (2, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );

		$this->assertParityRowCount(
			"INSERT INTO tmp_ci_items (id, name, payload) VALUES (2, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE name = VALUES(name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO tmp_ci_items (id, name, payload) VALUES (2, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, name, payload FROM tmp_ci_items ORDER BY id' );
	}

	public function test_case_insensitive_composite_unique_conflicts_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE site_options (
					id INTEGER PRIMARY KEY,
					site_id INTEGER NOT NULL DEFAULT 0,
					option_name VARCHAR(20) NOT NULL DEFAULT '',
					payload VARCHAR(20),
					UNIQUE KEY site_option (site_id, option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (1, 1, 'first', 'a')",
				"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (2, 2, 'FIRST', 'other-site')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'FIRST', 'duplicate')",
			'UNIQUE constraint failed'
		);

		$this->assertParityRowCount(
			"INSERT INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'FIRST', 'updated')
			ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), payload = VALUES(payload)"
		);
		$this->assertParityRows( 'SELECT id, site_id, option_name, payload FROM site_options ORDER BY id' );

		$this->assertParityRowCount( "REPLACE INTO site_options (id, site_id, option_name, payload) VALUES (3, 1, 'first', 'replaced')" );
		$this->assertParityRows( 'SELECT id, site_id, option_name, payload FROM site_options ORDER BY id' );
	}

	public function test_create_table_if_not_exists_with_secondary_index_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE IF NOT EXISTS wp_options (
					option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					PRIMARY KEY  (option_id),
					KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM wp_options',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
	}

	public function test_show_columns_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
	}

	public function test_show_columns_qualified_and_filtered_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		foreach (
			array(
				'SHOW COLUMNS FROM wp.metadata',
				"SHOW COLUMNS FROM `wp`.`metadata` LIKE 'option_%'",
				"SHOW COLUMNS FROM information_schema.metadata FROM wp WHERE Field = 'option_name'",
				"SHOW COLUMNS FROM wp.metadata WHERE Field = 'autoload'",
				"SHOW COLUMNS FROM wp.metadata WHERE Type = 'longtext'",
				"SHOW COLUMNS FROM wp.metadata WHERE `Null` = 'NO' AND `Key` = 'PRI'",
				"SHOW COLUMNS FROM wp.metadata WHERE `Default` = 'yes'",
				"SHOW COLUMNS FROM wp.metadata WHERE Extra = 'auto_increment'",
				'SHOW FULL COLUMNS FROM wp.metadata WHERE `Collation` IS NOT NULL',
				"SHOW FULL FIELDS IN `wp`.`metadata` WHERE `Comment` = 'Option name'",
				'DESCRIBE wp.metadata',
				"DESCRIBE `wp`.`metadata` 'option_%'",
			) as $sql
		) {
			$this->assertParityRows( $sql );
		}
	}

	public function test_qualified_create_table_and_show_indexes_after_use_information_schema_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'USE information_schema',
				'CREATE TABLE wp.qualified_ddl (
					id INT PRIMARY KEY,
					name VARCHAR(20),
					KEY idx_name (name)
				)',
			)
		);

		$this->assertParityRows( 'SHOW TABLES FROM wp' );
		$this->assertParityRows( 'SHOW CREATE TABLE wp.qualified_ddl' );
		$this->assertParityRows( 'SHOW COLUMNS FROM wp.qualified_ddl' );

		foreach (
			array(
				'SHOW INDEXES FROM wp.qualified_ddl',
				'SHOW INDEXES FROM information_schema.qualified_ddl FROM wp',
				'SHOW INDEXES FROM qualified_ddl FROM wp',
			) as $sql
		) {
			$this->assertParityRowColumns(
				$sql,
				array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
			);
		}

		$this->assertParityRows( 'SHOW INDEXES FROM qualified_ddl' );
	}

	public function test_qualified_create_index_after_use_information_schema_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE qualified_idx (id INT PRIMARY KEY, name VARCHAR(20))',
				'USE information_schema',
				'CREATE INDEX idx_name ON wp.qualified_idx (name)',
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEXES FROM wp.qualified_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);

		$this->runParitySetup( array( 'DROP INDEX idx_name ON wp.qualified_idx' ) );

		$this->assertParityRowColumns(
			'SHOW INDEXES FROM wp.qualified_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
	}

	public function test_alter_table_add_column_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata (option_name, option_value) VALUES ('siteurl', 'https://example.test')",
				"ALTER TABLE metadata ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
			)
		);

		$this->assertParityRows( 'SELECT option_name, option_value, autoload FROM metadata' );
		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
	}

	public function test_alter_table_change_modify_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					option_name VARCHAR(255) DEFAULT '7',
					option_value LONGTEXT,
					autoload VARCHAR(10) DEFAULT 'no'
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata (option_name, option_value, autoload) VALUES ('7', 'payload', 'no')",
				'ALTER TABLE metadata CHANGE COLUMN option_name option_name SMALLINT NOT NULL DEFAULT 14',
				"ALTER TABLE metadata MODIFY COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
				'ALTER TABLE metadata CHANGE COLUMN option_value option_payload LONGTEXT NULL',
				'INSERT INTO metadata (option_payload) VALUES (\'defaulted\')',
			)
		);

		$this->assertParityRows( 'SELECT option_name, option_payload, autoload FROM metadata ORDER BY option_payload' );
		$this->assertParityRows( 'SHOW COLUMNS FROM metadata' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, COLUMN_KEY, EXTRA
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY ordinal_position"
		);
	}

	public function test_alter_table_change_modify_key_rebuild_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata_key (
					id VARCHAR(20) NOT NULL,
					code VARCHAR(20) DEFAULT '7',
					payload VARCHAR(20),
					PRIMARY KEY (id),
					KEY code_idx (code)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"INSERT INTO metadata_key (id, code, payload) VALUES ('1', '10', 'alpha'), ('2', '11', 'bravo')",
				'ALTER TABLE metadata_key CHANGE COLUMN id id INT NOT NULL',
				"ALTER TABLE metadata_key MODIFY COLUMN code SMALLINT NOT NULL DEFAULT 42 COMMENT 'Numeric code'",
				"INSERT INTO metadata_key (id, payload) VALUES (3, 'charlie')",
			)
		);

		$this->assertParityRows( 'SELECT id, code, payload FROM metadata_key ORDER BY id' );
		$this->assertParityRows(
			"SELECT column_name, column_type, is_nullable, column_default, extra
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'metadata_key'
			ORDER BY ordinal_position"
		);
		$this->assertParityRowColumns(
			'SHOW INDEX FROM metadata_key',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT index_name AS INDEX_NAME, column_name AS COLUMN_NAME, non_unique AS NON_UNIQUE, sub_part AS SUB_PART
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'metadata_key'
			ORDER BY CASE WHEN index_name = 'PRIMARY' THEN 0 ELSE 1 END, index_name, seq_in_index"
		);
	}

	public function test_information_schema_columns_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					UNIQUE KEY option_name (option_name)
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
				"ALTER TABLE metadata ADD COLUMN autoload VARCHAR(20) NOT NULL DEFAULT 'yes'",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE,
				COLUMN_KEY, EXTRA, PRIVILEGES, COLUMN_COMMENT, GENERATION_EXPRESSION, SRS_ID
			FROM information_schema.columns
			WHERE table_name = 'metadata'
			ORDER BY ordinal_position"
		);
		$this->assertParityRows(
			"SELECT c.COLUMN_NAME, c.COLUMN_TYPE
			FROM information_schema.columns c
			WHERE c.TABLE_SCHEMA = 'wp' AND c.TABLE_NAME = 'metadata'
			ORDER BY c.ORDINAL_POSITION"
		);
	}

	public function test_serial_alias_storage_metadata_and_insert_id_shape_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE serial_type_family (
					id SERIAL,
					name VARCHAR(20)
				)',
			)
		);

		$this->assertParityRowCount( "INSERT INTO serial_type_family (name) VALUES ('first')" );
		$this->assertParityRowCount( "INSERT INTO serial_type_family (name) VALUES ('second')" );
		$this->assertParityRows( 'SELECT id, name FROM serial_type_family ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM serial_type_family' );
		$this->assertParityRows( 'DESCRIBE serial_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE serial_type_family' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM serial_type_family',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
				CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH,
				NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_TYPE, COLUMN_KEY, EXTRA
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'serial_type_family'
			ORDER BY ordinal_position"
		);
	}

	public function test_spatial_type_family_storage_and_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE spatial_type_family (
					id INT PRIMARY KEY,
					geom GEOMETRY,
					pt POINT,
					line LINESTRING,
					poly POLYGON,
					mpt MULTIPOINT,
					mline MULTILINESTRING,
					mpoly MULTIPOLYGON,
					gc GEOMETRYCOLLECTION
				)',
			)
		);

		$this->assertParityRowCount(
			"INSERT INTO spatial_type_family
				(id, geom, pt, line, poly, mpt, mline, mpoly, gc)
			VALUES
				(
					1,
					'POINT(1 1)',
					'POINT(2 2)',
					'LINESTRING(0 0, 1 1)',
					'POLYGON((0 0, 1 0, 0 1, 0 0))',
					'MULTIPOINT(1 1, 2 2)',
					'MULTILINESTRING((0 0, 1 1), (2 2, 3 3))',
					'MULTIPOLYGON(((0 0, 1 0, 0 1, 0 0)))',
					'GEOMETRYCOLLECTION(POINT(1 1), LINESTRING(0 0, 1 1))'
				)"
		);

		$this->assertParityRows( 'SELECT id, geom, pt, line, poly, mpt, mline, mpoly, gc FROM spatial_type_family ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM spatial_type_family' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM spatial_type_family' );
		$this->assertParityRows( 'DESCRIBE spatial_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE spatial_type_family' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'spatial_type_family'
			ORDER BY ordinal_position"
		);
	}

	public function test_alias_storage_type_family_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alias_storage_types (
					id INT PRIMARY KEY,
					real_col REAL,
					dec_col DEC,
					dec_ps_col DEC(10,2),
					fixed_col FIXED,
					binary_col BINARY,
					binary_len_col BINARY(8),
					varbinary_col VARBINARY(16)
				)',
				"INSERT INTO alias_storage_types
					(id, real_col, dec_col, dec_ps_col, fixed_col, binary_col, binary_len_col, varbinary_col)
				VALUES
					(1, '3.5', '4', 5.25, '6', B'01000001', 0x6263, x'646566')",
			)
		);

		$this->assertParityRows( 'SHOW COLUMNS FROM alias_storage_types' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM alias_storage_types' );
		$this->assertParityRows( 'DESCRIBE alias_storage_types' );
		$this->assertParityRows( 'SHOW CREATE TABLE alias_storage_types' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'alias_storage_types'
			ORDER BY ordinal_position"
		);
		$this->assertParityRows(
			'SELECT id,
				CAST(real_col * 10 AS SIGNED) AS real_x10,
				CAST(dec_col AS SIGNED) AS dec_int,
				CAST(dec_ps_col * 100 AS SIGNED) AS dec_ps_cents,
				CAST(fixed_col AS SIGNED) AS fixed_int
			FROM alias_storage_types
			ORDER BY id'
		);
		$this->assertParityRows(
			'SELECT LOWER(HEX(binary_col)) AS binary_hex,
				LOWER(HEX(binary_len_col)) AS binary_len_hex,
				LOWER(HEX(varbinary_col)) AS varbinary_hex
			FROM alias_storage_types
			ORDER BY id'
		);
	}

	public function test_json_type_family_storage_and_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE json_type_family (
					id INT PRIMARY KEY,
					data JSON,
					required JSON NOT NULL DEFAULT 'null'
				)",
			)
		);

		$this->assertParityRowCount( "INSERT INTO json_type_family (id, data, required) VALUES (1, '{\"a\":1}', '{\"required\":true}')" );
		$this->assertParityRowCount( 'INSERT INTO json_type_family (id, data) VALUES (2, TRUE)' );
		$this->assertParityRowCount( 'INSERT INTO json_type_family (id, data) VALUES (3, 0x62)' );
		$this->assertParityRowCount( "INSERT INTO json_type_family (id, data) VALUES (4, x'63')" );
		$this->assertParityRowCount( 'INSERT INTO json_type_family (id) VALUES (5)' );
		$this->assertParityRowCount( 'UPDATE json_type_family SET data = 123.456 WHERE id = 5' );

		$this->assertParityRows( 'SELECT id, data, required FROM json_type_family ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM json_type_family' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM json_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE json_type_family' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'json_type_family'
			ORDER BY ordinal_position"
		);
	}

	public function test_year_type_family_storage_and_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE year_type_family (
					id INT PRIMARY KEY,
					observed YEAR,
					required YEAR NOT NULL DEFAULT '0000'
				)",
			)
		);

		$this->assertParityRowCount( "INSERT INTO year_type_family (id, observed, required) VALUES (1, '2024', '2020')" );
		$this->assertParityRowCount( 'INSERT INTO year_type_family (id, observed) VALUES (2, 2025)' );
		$this->assertParityRowCount( "UPDATE year_type_family SET observed = '2026' WHERE id = 2" );

		$this->assertParityRows( 'SELECT id, observed, required FROM year_type_family ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM year_type_family' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM year_type_family' );
		$this->assertParityRows( 'DESCRIBE year_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE year_type_family' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'year_type_family'
			ORDER BY ordinal_position"
		);
	}

	public function test_enum_set_type_family_storage_and_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE enum_set_type_family (
					id INT PRIMARY KEY,
					status ENUM('red', 'green', 'blue') NOT NULL DEFAULT 'green',
					flags SET('read', 'write', 'exec') DEFAULT 'read,exec',
					quoted ENUM('plain', 'b''b', 'longer') DEFAULT 'b''b'
				)",
			)
		);

		$this->assertParityRowCount( 'INSERT INTO enum_set_type_family (id) VALUES (1)' );
		$this->assertParityRowCount( "INSERT INTO enum_set_type_family (id, status, flags, quoted) VALUES (2, 'purple', 'read,bogus', 'plain')" );
		$this->assertParityRowCount( "INSERT INTO enum_set_type_family (id, status, flags, quoted) VALUES (3, '', '', 'longer')" );
		$this->assertParityRowCount( "UPDATE enum_set_type_family SET status = 'cerulean', flags = 'anything' WHERE id = 3" );

		$this->assertParityRows( 'SELECT id, status, flags, quoted FROM enum_set_type_family ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM enum_set_type_family' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM enum_set_type_family' );
		$this->assertParityRows( 'DESCRIBE enum_set_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE enum_set_type_family' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
				CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH,
				CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'enum_set_type_family'
			ORDER BY ordinal_position"
		);

		$this->assertParityRowCount( "SET SESSION sql_mode = ''" );
		$this->runParitySetup(
			array(
				"CREATE TABLE enum_set_implicit_defaults (
					id INT PRIMARY KEY,
					status ENUM('red', 'green') NOT NULL,
					flags SET('read', 'write') NOT NULL
				)",
			)
		);
		$this->assertParityRowCount( 'INSERT INTO enum_set_implicit_defaults (id) VALUES (1)' );
		$this->assertParityRows( 'SELECT id, status, flags FROM enum_set_implicit_defaults ORDER BY id' );
	}

	public function test_national_character_type_family_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE national_character_types (
					id INT PRIMARY KEY,
					plain_nchar NCHAR DEFAULT 'a',
					nchar_len NCHAR(10) DEFAULT 'bee',
					national_plain NATIONAL CHAR DEFAULT 'c',
					national_len NATIONAL CHAR (10) DEFAULT 'dee',
					nchar_varchar NCHAR VARCHAR(255) DEFAULT 'echo',
					nchar_varying NCHAR VARYING(32) DEFAULT 'foxtrot',
					nvarchar_col NVARCHAR(20) DEFAULT 'golf',
					national_varchar NATIONAL VARCHAR(30) DEFAULT 'hotel',
					national_char_varying NATIONAL CHAR VARYING(40) DEFAULT 'india',
					national_character_varying NATIONAL CHARACTER VARYING(50) DEFAULT 'juliet'
				)",
				'INSERT INTO national_character_types (id) VALUES (1)',
				"INSERT INTO national_character_types
					(id, plain_nchar, nchar_len, national_plain, national_len,
						nchar_varchar, nchar_varying, nvarchar_col, national_varchar,
						national_char_varying, national_character_varying)
				VALUES
					(2, 'aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh', 'ii', 'jj')",
			)
		);

		$this->assertParityRows( 'SHOW COLUMNS FROM national_character_types' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM national_character_types' );
		$this->assertParityRows( 'DESCRIBE national_character_types' );
		$this->assertParityRows( 'SHOW CREATE TABLE national_character_types' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'national_character_types'
				AND column_name <> 'id'
			ORDER BY ordinal_position"
		);
		$this->assertParityRows(
			'SELECT id, plain_nchar, nchar_len, national_plain, national_len,
				nchar_varchar, nchar_varying, nvarchar_col, national_varchar,
				national_char_varying, national_character_varying
			FROM national_character_types
			ORDER BY id'
		);
	}

	public function test_bit_type_family_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE bit_type_family (
					id INT PRIMARY KEY,
					plain BIT,
					flags BIT(4) DEFAULT b'0101',
					from_hex BIT(8) DEFAULT 0x0a,
					quoted_zero BIT(1) DEFAULT '0',
					integer_five BIT(4) DEFAULT 5,
					truthy BIT(1) DEFAULT TRUE,
					falsey BIT(1) DEFAULT FALSE
				)",
				'INSERT INTO bit_type_family (id, plain) VALUES (1, 3)',
				"INSERT INTO bit_type_family
					(id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey)
				VALUES
					(2, 4, 5, 6, 1, '7', FALSE, TRUE)",
				"INSERT INTO bit_type_family
					(id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey)
				VALUES
					(3, '7', 8, 9, 0, 10, TRUE, FALSE)",
				'UPDATE bit_type_family
				SET plain = 10,
					flags = 11,
					from_hex = 12,
					truthy = TRUE,
					falsey = FALSE
				WHERE id = 3',
			)
		);

		$this->assertParityRows( 'SHOW COLUMNS FROM bit_type_family' );
		$this->assertParityRows( 'SHOW FULL COLUMNS FROM bit_type_family' );
		$this->assertParityRows( 'DESCRIBE bit_type_family' );
		$this->assertParityRows( 'SHOW CREATE TABLE bit_type_family' );
		$this->assertParityRows(
			"SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
				CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
				CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
			FROM information_schema.columns
			WHERE table_schema = 'wp'
				AND table_name = 'bit_type_family'
			ORDER BY ordinal_position"
		);
		$this->assertParityRows(
			'SELECT id, plain, flags, from_hex, quoted_zero, integer_five, truthy, falsey
			FROM bit_type_family
			ORDER BY id'
		);
	}

	public function test_information_schema_statistics_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					nullable_value VARCHAR(191),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload),
					KEY nullable_value (nullable_value),
					KEY option_value_prefix (option_value(12))
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, NON_UNIQUE, INDEX_SCHEMA, INDEX_NAME,
				SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY, SUB_PART, PACKED, NULLABLE,
				INDEX_TYPE, COMMENT, INDEX_COMMENT, IS_VISIBLE, EXPRESSION
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT s.INDEX_NAME, s.COLUMN_NAME, s.SUB_PART
			FROM information_schema.statistics s
			WHERE s.TABLE_SCHEMA = 'wp' AND s.TABLE_NAME = 'metadata'
			ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX"
		);
	}

	public function test_information_schema_constraint_metadata_matches_sqlite(): void {
		$this->assertParityRows(
			"SELECT COUNT(*) AS count
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'"
		);
		$this->assertParityRows(
			"SELECT COUNT(*) AS count
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'"
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE empty_table (id INT, note TEXT)',
				"CREATE TABLE metadata (
					site_id BIGINT(20) UNSIGNED NOT NULL,
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					payload LONGTEXT,
					PRIMARY KEY (site_id, option_id),
					UNIQUE KEY unique_site_option (site_id, option_name),
					KEY payload_prefix (payload(12))
				) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_NAME, CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'empty_table'"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_SCHEMA,
				TABLE_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
				TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'metadata'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityRows(
			"SELECT tc.CONSTRAINT_NAME AS name, tc.CONSTRAINT_TYPE AS type,
				k.COLUMN_NAME AS col, k.ORDINAL_POSITION AS pos
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.key_column_usage AS k
				ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
				AND k.TABLE_SCHEMA = tc.TABLE_SCHEMA
				AND k.TABLE_NAME = tc.TABLE_NAME
			WHERE tc.TABLE_SCHEMA = 'wp' AND tc.TABLE_NAME = 'metadata'
			ORDER BY name, pos"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND constraint_name = 'payload_prefix'"
		);
	}

	public function test_table_level_check_constraints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE check_metadata (
					id INT,
					amount INT,
					CONSTRAINT amount_positive CHECK (amount > 0),
					CHECK (id IS NULL OR id >= 0)
				)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO check_metadata (id, amount) VALUES (1, 10)' );
		$this->assertParityErrorContains(
			'INSERT INTO check_metadata (id, amount) VALUES (2, -1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'INSERT INTO check_metadata (id, amount) VALUES (-1, 1)',
			'CHECK constraint failed'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'check_metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, cc.CHECK_CLAUSE
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.check_constraints AS cc
				ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
			WHERE tc.table_schema = 'wp' AND tc.table_name = 'check_metadata'
			ORDER BY tc.constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE check_metadata' );
	}

	public function test_inline_check_constraints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE inline_check_metadata (
					id INT CHECK (id >= 0),
					amount INT CHECK (amount > 0) ENFORCED
				)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO inline_check_metadata (id, amount) VALUES (1, 10)' );
		$this->assertParityErrorContains(
			'INSERT INTO inline_check_metadata (id, amount) VALUES (-1, 1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'INSERT INTO inline_check_metadata (id, amount) VALUES (2, -1)',
			'CHECK constraint failed'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_check_metadata SET amount = 0 WHERE id = 1',
			'CHECK constraint failed'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'inline_check_metadata'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE inline_check_metadata' );
	}

	public function test_check_not_enforced_constraints_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE check_not_enforced (
					id INT CHECK (id > 0) NOT ENFORCED,
					amount INT,
					CONSTRAINT amount_limit CHECK (amount < 5) NOT ENFORCED,
					CONSTRAINT amount_positive CHECK (amount > 0)
				)',
				'INSERT INTO check_not_enforced (id, amount) VALUES (0, 10)',
				'ALTER TABLE check_not_enforced ADD CONSTRAINT added_less_than_five CHECK (amount < 5) NOT ENFORCED',
				'ALTER TABLE check_not_enforced ADD CHECK (id > 10) NOT ENFORCED',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO check_not_enforced (id, amount) VALUES (0, 10)' );
		$this->assertParityErrorContains(
			'INSERT INTO check_not_enforced (id, amount) VALUES (1, -1)',
			'CHECK constraint failed'
		);
		$this->assertParityRows( 'SELECT id, amount FROM check_not_enforced ORDER BY id, amount' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'check_not_enforced'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.check_constraints AS cc
				ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
				AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
			WHERE tc.table_schema = 'wp' AND tc.table_name = 'check_not_enforced'
			ORDER BY tc.constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE check_not_enforced' );

		$this->assertParityRowCount( 'ALTER TABLE check_not_enforced DROP CHECK added_less_than_five' );
		$this->assertParityRowCount( 'ALTER TABLE check_not_enforced DROP CONSTRAINT check_not_enforced_chk_2' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'check_not_enforced'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE check_not_enforced' );
		$this->assertParityRowCount( 'INSERT INTO check_not_enforced (id, amount) VALUES (0, 10)' );
	}

	public function test_simple_table_level_foreign_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE fk_parent (id INT PRIMARY KEY)',
				'CREATE TABLE fk_child (
					id INT,
					parent_id INT,
					CONSTRAINT fk_parent_ref FOREIGN KEY (parent_id) REFERENCES fk_parent (id) ON DELETE RESTRICT ON UPDATE NO ACTION
				)',
				'CREATE TABLE fk_child_generated (
					id INT,
					parent_id INT,
					FOREIGN KEY (parent_id) REFERENCES fk_parent (id)
				)',
				'INSERT INTO fk_parent (id) VALUES (1)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO fk_child (id, parent_id) VALUES (10, 1)' );
		$this->assertParityRowCount( 'INSERT INTO fk_child_generated (id, parent_id) VALUES (20, 1)' );
		$this->assertParityErrorContains(
			'INSERT INTO fk_child (id, parent_id) VALUES (11, 404)',
			'constraint'
		);
		$this->assertParityErrorContains(
			'DELETE FROM fk_parent WHERE id = 1',
			'constraint'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME,
				UNIQUE_CONSTRAINT_CATALOG, UNIQUE_CONSTRAINT_SCHEMA,
				UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE,
				TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('fk_child', 'fk_child_generated')
			ORDER BY table_name, constraint_name, ordinal_position"
		);
		$this->assertParityRows(
			"SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME,
				k.REFERENCED_COLUMN_NAME
			FROM information_schema.referential_constraints AS rc
			JOIN information_schema.key_column_usage AS k
				ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
				AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
			WHERE rc.constraint_schema = 'wp'
			ORDER BY rc.table_name, rc.constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE fk_child' );
		$this->assertParityRows( 'SHOW CREATE TABLE fk_child_generated' );
	}

	public function test_simple_inline_foreign_keys_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE inline_fk_parent (id INT PRIMARY KEY)',
				'CREATE TABLE inline_fk_child (
					id INT,
					parent_id INT REFERENCES inline_fk_parent (id) ON DELETE RESTRICT ON UPDATE NO ACTION
				)',
				'CREATE TABLE inline_fk_child_default (
					id INT,
					parent_id INT REFERENCES inline_fk_parent (id)
				)',
				'INSERT INTO inline_fk_parent (id) VALUES (1)',
			)
		);

		$this->assertParityRowCount( 'INSERT INTO inline_fk_child (id, parent_id) VALUES (10, 1)' );
		$this->assertParityRowCount( 'INSERT INTO inline_fk_child_default (id, parent_id) VALUES (20, 1)' );
		$this->assertParityErrorContains(
			'INSERT INTO inline_fk_child (id, parent_id) VALUES (11, 404)',
			'constraint'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_fk_child SET parent_id = 404 WHERE id = 10',
			'constraint'
		);
		$this->assertParityErrorContains(
			'UPDATE inline_fk_parent SET id = 2 WHERE id = 1',
			'constraint'
		);
		$this->assertParityErrorContains(
			'DELETE FROM inline_fk_parent WHERE id = 1',
			'constraint'
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION,
				UPDATE_RULE, DELETE_RULE, TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
				REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name IN ('inline_fk_child', 'inline_fk_child_default')
			ORDER BY table_name, constraint_name, ordinal_position"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE inline_fk_child' );
		$this->assertParityRows( 'SHOW CREATE TABLE inline_fk_child_default' );
	}

	public function test_alter_table_check_constraint_actions_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_check_gap (id INT, label VARCHAR(20), CONSTRAINT existing_check CHECK (id >= 0), KEY label_idx (label))',
				"INSERT INTO alter_check_gap (id, label) VALUES (1, 'one')",
				'ALTER TABLE alter_check_gap ADD CONSTRAINT added_check CHECK (id < 10)',
				'ALTER TABLE alter_check_gap ADD CHECK (id IS NULL OR id <> 7)',
			)
		);

		$this->assertParityRows( 'SHOW CREATE TABLE alter_check_gap' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_check_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityErrorContains(
			"INSERT INTO alter_check_gap (id, label) VALUES (20, 'too_high')",
			'CHECK constraint failed'
		);
		$this->assertParityRowColumns(
			'SHOW INDEX FROM alter_check_gap',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);

		$this->assertParityRowCount( 'ALTER TABLE alter_check_gap DROP CHECK existing_check' );
		$this->assertParityRowCount( 'ALTER TABLE alter_check_gap DROP CONSTRAINT added_check' );
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_check_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CHECK_CLAUSE
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'wp'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE alter_check_gap' );
		$this->assertParityRowCount( "INSERT INTO alter_check_gap (id, label) VALUES (-1, 'after_drop')" );
	}

	public function test_alter_table_add_unique_constraint_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_unique_gap (
					id INT PRIMARY KEY,
					tenant_id INT,
					slug VARCHAR(50),
					name VARCHAR(50)
				)',
				"INSERT INTO alter_unique_gap (id, tenant_id, slug, name) VALUES
					(1, 1, 'home', 'Home'),
					(2, 1, 'about', 'About')",
				'ALTER TABLE alter_unique_gap ADD CONSTRAINT name_unique UNIQUE (name)',
				'ALTER TABLE alter_unique_gap ADD CONSTRAINT tenant_slug_unique UNIQUE (tenant_id, slug)',
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM alter_unique_gap',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRowColumns(
			'SHOW COLUMNS FROM alter_unique_gap',
			array( 'Field', 'Key' )
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_unique_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'alter_unique_gap'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name = 'alter_unique_gap'
				AND referenced_table_name IS NULL
			ORDER BY constraint_name, ordinal_position"
		);
	}

	public function test_alter_table_drop_unique_constraint_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_unique_drop_gap (
					id INT PRIMARY KEY,
					name VARCHAR(50)
				)',
				"INSERT INTO alter_unique_drop_gap (id, name) VALUES (1, 'first'), (2, 'second')",
				'ALTER TABLE alter_unique_drop_gap ADD CONSTRAINT name_unique UNIQUE (name)',
			)
		);

		$this->assertParityRowCount( 'ALTER TABLE alter_unique_drop_gap DROP CONSTRAINT name_unique' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM alter_unique_drop_gap',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRowColumns(
			'SHOW COLUMNS FROM alter_unique_drop_gap',
			array( 'Field', 'Key' )
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'alter_unique_drop_gap'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'alter_unique_drop_gap'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp'
				AND table_name = 'alter_unique_drop_gap'
				AND referenced_table_name IS NULL
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityRowCount( "INSERT INTO alter_unique_drop_gap (id, name) VALUES (3, 'first')" );
		$this->assertParityRows( 'SELECT id, name FROM alter_unique_drop_gap ORDER BY id' );
	}

	public function test_unique_key_foreign_key_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE unique_parent (
					id INT PRIMARY KEY,
					code INT,
					UNIQUE KEY code_u (code)
				)',
				'CREATE TABLE unique_child (
					id INT,
					parent_code INT,
					CONSTRAINT fk_parent_code FOREIGN KEY (parent_code) REFERENCES unique_parent (code)
				)',
			)
		);

		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'unique_child'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
			FROM information_schema.referential_constraints
			WHERE constraint_schema = 'wp' AND table_name = 'unique_child'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
				POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_NAME,
				REFERENCED_COLUMN_NAME
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'unique_child'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE unique_child' );
	}

	public function test_information_schema_tables_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				'CREATE TABLE plain (id INT, name TEXT)',
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
			)
		);

		$this->assertParityRows(
			"SELECT TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, VERSION,
				ROW_FORMAT, AVG_ROW_LENGTH, DATA_LENGTH, MAX_DATA_LENGTH,
				INDEX_LENGTH, DATA_FREE, `AUTO_INCREMENT`, UPDATE_TIME, CHECK_TIME,
				TABLE_COLLATION, CHECKSUM, CREATE_OPTIONS, TABLE_COMMENT
			FROM information_schema.tables
			WHERE table_schema = 'wp'
			ORDER BY table_name"
		);
		$this->assertParityRows(
			"SELECT t.TABLE_NAME, t.ENGINE, t.`AUTO_INCREMENT`
			FROM information_schema.tables t
			WHERE t.TABLE_SCHEMA = 'wp'
			ORDER BY t.TABLE_NAME"
		);
	}

	public function test_check_table_status_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE check_items (id INT)',
				'CREATE TABLE check_second (id INT)',
				'CREATE TEMPORARY TABLE check_temp_only (id INT)',
				'INSERT INTO check_items VALUES (1)',
			)
		);

		$this->assertParityRows( 'SELECT id FROM check_items' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'CHECK TABLE check_items' );
		$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
		$this->assertParityRows( 'CHECK TABLE wp.check_items' );
		$this->assertParityRows( 'CHECK TABLES check_items' );
		$this->assertParityRows( 'CHECK TABLE check_items QUICK FAST MEDIUM EXTENDED CHANGED FOR UPGRADE' );
		$this->assertParityRows( 'CHECK TABLE check_items, check_second' );
		$this->assertParityRows( 'CHECK TABLE check_temp_only' );
		$this->assertParityRows( 'CHECK TABLE missing_check_table' );
		$this->assertParityRows( 'CHECK TABLE check_items, missing_check_table' );
		$this->assertParityErrorContains( 'CHECK TABLE information_schema.tables', "to database 'information_schema'" );
	}

	public function test_administration_table_status_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE admin_items (id INT)',
				'CREATE TABLE admin_second (id INT)',
				'CREATE TEMPORARY TABLE admin_temp_only (id INT)',
				'CREATE TABLE admin_shadow (base_id INT)',
				'CREATE TEMPORARY TABLE admin_shadow (temp_id INT)',
				'INSERT INTO admin_items VALUES (1)',
				'INSERT INTO admin_shadow VALUES (9)',
			)
		);

		foreach ( array( 'ANALYZE TABLE', 'OPTIMIZE TABLE', 'REPAIR TABLE' ) as $statement ) {
			$this->assertParityRows( 'SELECT id FROM admin_items' );
			$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
			$this->assertParityRows( $statement . ' admin_items' );
			$this->assertParityRows( 'SELECT FOUND_ROWS() AS found_rows' );
			$this->assertParityRows( $statement . ' wp.admin_items' );
			$this->assertParityRows( str_replace( ' TABLE', ' TABLES', $statement ) . ' admin_items' );
			$this->assertParityRows( $statement . ' admin_items, admin_second' );
			$this->assertParityRows( $statement . ' admin_temp_only' );
			$this->assertParityRows( $statement . ' admin_shadow' );
			$this->assertParityRows( $statement . ' missing_admin_table' );
			$this->assertParityRows( $statement . ' admin_items, missing_admin_table' );
			$this->assertParityErrorContains( $statement . ' information_schema.tables', "to database 'information_schema'" );
			$this->assertParityErrorContains( $statement . ' admin_items, information_schema.tables', "to database 'information_schema'" );
		}

		$this->assertParityRows( 'ANALYZE LOCAL TABLE admin_items' );
		$this->assertParityRows( 'ANALYZE NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'OPTIMIZE LOCAL TABLE admin_items' );
		$this->assertParityRows( 'OPTIMIZE NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'REPAIR LOCAL TABLE admin_items' );
		$this->assertParityRows( 'REPAIR NO_WRITE_TO_BINLOG TABLES admin_items' );
		$this->assertParityRows( 'REPAIR TABLE admin_items QUICK' );
		$this->assertParityRows( 'REPAIR TABLE admin_items EXTENDED' );
		$this->assertParityRows( 'REPAIR TABLE admin_items USE_FRM' );
		$this->assertParityRows( 'REPAIR TABLE admin_items QUICK EXTENDED USE_FRM' );
		$this->assertParityRows( 'ANALYZE TABLE admin_items UPDATE HISTOGRAM ON id' );
		$this->assertParityRows( 'ANALYZE TABLE admin_items DROP HISTOGRAM ON id' );
		$this->assertParityErrorContains( 'OPTIMIZE TABLE admin_items QUICK', 'parse' );

		$this->assertParityRows(
			"SELECT TABLE_NAME
			FROM information_schema.tables
			WHERE TABLE_NAME = 'admin_temp_only'
				OR TABLE_NAME LIKE '__wp_duckdb_%'
			ORDER BY TABLE_NAME"
		);
		$this->assertParityRows( 'SELECT temp_id FROM admin_shadow' );
	}

	public function test_show_table_status_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					option_value LONGTEXT NOT NULL
				) ENGINE=MyISAM CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				'CREATE TABLE plain (id INT, name TEXT)',
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
			)
		);

		$columns = array(
			'Name',
			'Engine',
			'Version',
			'Row_format',
			'Avg_row_length',
			'Data_length',
			'Max_data_length',
			'Index_length',
			'Data_free',
			'Auto_increment',
			'Update_time',
			'Check_time',
			'Collation',
			'Checksum',
			'Create_options',
			'Comment',
		);

		$this->assertParityRowColumns( 'SHOW TABLE STATUS FROM wp', $columns );
		$this->assertParityRowColumns( "SHOW TABLE STATUS IN wp LIKE 'plain'", $columns );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS WHERE `Auto_increment` > 2', array( 'Name', 'Auto_increment' ) );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS WHERE `Auto_increment` IS NULL', array( 'Name', 'Auto_increment' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS WHERE SUBSTR(Name, 1, 4) = 'meta'", array( 'Name' ) );
		$this->assertParityRowColumns( 'SHOW TABLE STATUS FROM other_database', $columns );
	}

	public function test_show_create_table_metadata_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE metadata (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					option_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Option name',
					option_value LONGTEXT NOT NULL,
					autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
					PRIMARY KEY (id),
					UNIQUE KEY option_name (option_name),
					KEY autoload (autoload),
					KEY option_value_prefix (option_value(12))
				) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Options table'",
				"INSERT INTO metadata (option_name, option_value)
				VALUES ('siteurl', 'https://example.test'), ('home', 'https://example.test')",
				"CREATE TABLE composite_pk (
					site_id BIGINT(20) UNSIGNED NOT NULL,
					option_id BIGINT(20) UNSIGNED NOT NULL,
					option_name VARCHAR(191) NOT NULL DEFAULT '',
					PRIMARY KEY (site_id, option_id),
					UNIQUE KEY unique_site_option (site_id, option_name)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
				'CREATE TABLE plain (id INT, name TEXT)',
			)
		);

		$this->assertParityRows( 'SHOW CREATE TABLE metadata' );
		$this->assertParityRows( 'SHOW CREATE TABLE wp.metadata' );
		$this->assertParityRows( 'SHOW CREATE TABLE composite_pk' );
		$this->assertParityRows( 'SHOW CREATE TABLE plain' );
		$this->assertParityRows( 'SHOW CREATE TABLE missing' );
	}

	public function test_drop_table_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_drop' ),
				'CREATE TABLE survivor (id INT, note VARCHAR(20))',
				"INSERT INTO lifecycle_drop (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')",
				'DROP TABLE lifecycle_drop',
			)
		);

		$this->assertParityRows( 'SHOW TABLES' );
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_drop' );
		$this->assertParityRows(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT column_name
			FROM information_schema.columns
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT index_name
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);
		$this->assertParityRows(
			"SELECT constraint_name
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_drop'"
		);

		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_drop' ),
				"INSERT INTO lifecycle_drop (name, payload) VALUES ('fresh', 'value')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM lifecycle_drop ORDER BY id' );
	}

	public function test_truncate_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_truncate' ),
				"INSERT INTO lifecycle_truncate (name, payload) VALUES ('a', 'alpha'), ('b', 'bravo')",
				'DELETE FROM lifecycle_truncate WHERE name = \'b\'',
			)
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);

		$this->runParitySetup( array( 'TRUNCATE TABLE wp.lifecycle_truncate' ) );
		$this->assertParityRows( 'SELECT COUNT(*) AS count FROM lifecycle_truncate' );
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_truncate' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_truncate',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'lifecycle_truncate'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_truncate' );

		$this->runParitySetup( array( "INSERT INTO lifecycle_truncate (name, payload) VALUES ('z', 'zulu')" ) );
		$this->assertParityRows( 'SELECT id, name FROM lifecycle_truncate ORDER BY id' );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_truncate'"
		);
	}

	public function test_create_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE seeded_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(20)
				) AUTO_INCREMENT=100',
				'CREATE TABLE seeded_plain (id INT, name VARCHAR(20)) AUTO_INCREMENT=500',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO seeded_ai (name) VALUES ('a')" ) );
		$this->assertParityRows( 'SELECT id, name FROM seeded_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'seeded_ai'"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE seeded_ai' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'seeded_plain'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE seeded_plain' );
	}

	public function test_alter_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE alter_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				)',
				'ALTER TABLE alter_ai AUTO_INCREMENT = 50',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO alter_ai (name) VALUES ('first')" ) );
		$this->assertParityRows( 'SELECT id, name FROM alter_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );

		$this->runParitySetup( array( 'ALTER TABLE alter_ai AUTO_INCREMENT = 200' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_ai' );

		$this->runParitySetup( array( 'ALTER TABLE alter_ai AUTO_INCREMENT = 1' ) );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_ai'", array( 'Name', 'Auto_increment' ) );
		$this->runParitySetup( array( "INSERT INTO alter_ai (name) VALUES ('second')" ) );
		$this->assertParityRows( 'SELECT id, name FROM alter_ai ORDER BY id' );

		$this->runParitySetup(
			array(
				'CREATE TABLE alter_plain (id INT, name TEXT) AUTO_INCREMENT=500',
				'ALTER TABLE alter_plain AUTO_INCREMENT = 900',
			)
		);
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'alter_plain'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_plain' );
	}

	public function test_alter_table_options_and_key_maintenance_noops_match_sqlite(): void {
		$this->runParitySetup(
			array(
				"CREATE TABLE alter_options (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(20)
				) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT='Original comment'",
				"INSERT INTO alter_options (name) VALUES ('first'), ('second')",
			)
		);

		foreach (
			array(
				'ALTER TABLE alter_options ENGINE=InnoDB',
				'ALTER TABLE alter_options DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				"ALTER TABLE alter_options COMMENT = 'Ignored comment'",
				'ALTER TABLE alter_options ROW_FORMAT=DYNAMIC',
				'ALTER TABLE alter_options DISABLE KEYS',
				'ALTER TABLE alter_options ENABLE KEYS',
			) as $sql
		) {
			$this->assertParityRowCount( $sql );
		}

		$this->assertParityRows( 'SELECT id, name FROM alter_options ORDER BY id' );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_options' );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'alter_options'"
		);

		$this->assertParityRowCount( 'ALTER TABLE alter_options AUTO_INCREMENT = 50, ENGINE=InnoDB, DISABLE KEYS' );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'alter_options'"
		);

		$this->runParitySetup( array( "INSERT INTO alter_options (name) VALUES ('third')" ) );
		$this->assertParityRows( 'SELECT id, name FROM alter_options ORDER BY id' );
		$this->assertParityRows( 'SHOW CREATE TABLE alter_options' );
	}

	public function test_temporary_table_auto_increment_seed_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE shadow_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				)',
				"INSERT INTO shadow_ai (name) VALUES ('p1'), ('p2')",
				'CREATE TEMPORARY TABLE shadow_ai (
					id INT AUTO_INCREMENT PRIMARY KEY,
					name TEXT
				) AUTO_INCREMENT=500',
			)
		);

		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'shadow_ai'", array( 'Name', 'Auto_increment' ) );
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'shadow_ai'"
		);

		$this->runParitySetup( array( "INSERT INTO shadow_ai (name) VALUES ('temp-a')" ) );
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
		$this->runParitySetup(
			array(
				'ALTER TABLE shadow_ai AUTO_INCREMENT = 1000',
				"INSERT INTO shadow_ai (name) VALUES ('temp-b')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
		$this->assertParityRowColumns( "SHOW TABLE STATUS LIKE 'shadow_ai'", array( 'Name', 'Auto_increment' ) );

		$this->runParitySetup(
			array(
				'DROP TEMPORARY TABLE shadow_ai',
				"INSERT INTO shadow_ai (name) VALUES ('p3')",
			)
		);
		$this->assertParityRows( 'SELECT id, name FROM shadow_ai ORDER BY id' );
	}

	public function test_drop_index_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				$this->lifecycleTableSql( 'lifecycle_idx' ),
				"INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'alpha')",
				'DROP INDEX payload_prefix ON lifecycle_idx',
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_idx' );
		$this->assertParityRows(
			"SELECT index_name, column_name, sub_part
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_idx' );

		$this->runParitySetup(
			array(
				'DROP INDEX name_unique ON wp.lifecycle_idx',
				"INSERT INTO lifecycle_idx (name, payload) VALUES ('a', 'duplicate')",
			)
		);

		$this->assertParityRowColumns(
			'SHOW INDEX FROM lifecycle_idx',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW COLUMNS FROM lifecycle_idx' );
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'lifecycle_idx'
			ORDER BY constraint_name"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE lifecycle_idx' );
		$this->assertParityRows( 'SELECT name FROM lifecycle_idx ORDER BY id' );
	}

	public function test_alter_table_drop_primary_key_lifecycle_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk (
					id INT NOT NULL,
					name VARCHAR(20) NOT NULL,
					amount INT,
					PRIMARY KEY (id),
					UNIQUE KEY name_unique (name),
					KEY amount_idx (amount),
					CONSTRAINT amount_positive CHECK (amount > 0)
				)',
				"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (1, 'a', 10), (2, 'b', 20)",
				'ALTER TABLE ddl_drop_pk DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (1, 'c', 30)",
			)
		);

		$this->assertParityRows( 'SELECT id, name, amount FROM ddl_drop_pk ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk' );
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk' );
		$this->assertParityRows(
			"SELECT index_name, column_name, non_unique
			FROM information_schema.statistics
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY index_name, seq_in_index"
		);
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY constraint_name"
		);
		$this->assertParityRows(
			"SELECT constraint_name, column_name, referenced_table_name
			FROM information_schema.key_column_usage
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk'
			ORDER BY constraint_name, ordinal_position"
		);
		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (3, 'a', 40)",
			'constraint'
		);
		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk (id, name, amount) VALUES (4, 'd', 0)",
			'CHECK constraint failed'
		);
	}

	public function test_alter_table_drop_primary_index_quoted_aliases_match_sqlite(): void {
		foreach ( array( 'DROP INDEX `PRIMARY`', 'DROP KEY `PRIMARY`' ) as $drop_action ) {
			$table_name = 'ddl_drop_pk_alias_' . strtolower( str_replace( array( 'DROP ', ' `PRIMARY`' ), '', $drop_action ) );
			$this->runParitySetup(
				array(
					"CREATE TABLE {$table_name} (
						id INT NOT NULL,
						name VARCHAR(20),
						PRIMARY KEY (id),
						KEY name_idx (name)
					)",
					"INSERT INTO {$table_name} (id, name) VALUES (1, 'a'), (2, 'b')",
					"ALTER TABLE {$table_name} {$drop_action}",
					"INSERT INTO {$table_name} (id, name) VALUES (1, 'duplicate')",
				)
			);

			$this->assertParityRows( "SELECT id, name FROM {$table_name} ORDER BY id, name" );
			$this->assertParityRows( "SHOW COLUMNS FROM {$table_name}" );
			$this->assertParityRowColumns(
				"SHOW INDEX FROM {$table_name}",
				array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
			);
			$this->assertParityRows( "SHOW CREATE TABLE {$table_name}" );
		}
	}

	public function test_alter_table_drop_primary_index_unquoted_aliases_reject_without_mutation_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_unquoted (id INT PRIMARY KEY, name VARCHAR(20), KEY name_idx (name))',
				"INSERT INTO ddl_drop_pk_unquoted (id, name) VALUES (1, 'a')",
			)
		);

		foreach ( array( 'DROP INDEX PRIMARY', 'DROP KEY PRIMARY' ) as $drop_action ) {
			$this->assertParityErrorContains(
				'ALTER TABLE ddl_drop_pk_unquoted ' . $drop_action,
				'parse'
			);
			$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_unquoted ORDER BY id' );
			$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_unquoted' );
			$this->assertParityRowColumns(
				'SHOW INDEX FROM ddl_drop_pk_unquoted',
				array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
			);
			$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_unquoted' );
		}
	}

	public function test_alter_table_drop_composite_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_composite_pk (
					site_id INT NOT NULL,
					object_id INT NOT NULL,
					name VARCHAR(20),
					PRIMARY KEY (site_id, object_id),
					KEY name_idx (name)
				)',
				"INSERT INTO ddl_drop_composite_pk (site_id, object_id, name) VALUES (1, 10, 'a'), (1, 11, 'b')",
				'ALTER TABLE ddl_drop_composite_pk DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_composite_pk (site_id, object_id, name) VALUES (1, 10, 'duplicate')",
			)
		);

		$this->assertParityRows( 'SELECT site_id, object_id, name FROM ddl_drop_composite_pk ORDER BY site_id, object_id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_composite_pk' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_composite_pk',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_composite_pk' );
		$this->assertParityRows(
			"SELECT constraint_name, constraint_type
			FROM information_schema.table_constraints
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_composite_pk'
			ORDER BY constraint_name"
		);
	}

	public function test_alter_table_drop_auto_increment_primary_key_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_ai (
					id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(20) NOT NULL,
					KEY name_idx (name)
				)',
				"INSERT INTO ddl_drop_pk_ai (name) VALUES ('a'), ('b')",
				'ALTER TABLE ddl_drop_pk_ai DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk_ai (name) VALUES ('generated_after_drop')",
			)
		);

		$this->assertParityErrorContains(
			"INSERT INTO ddl_drop_pk_ai (id, name) VALUES (1, 'explicit_duplicate')",
			'UNIQUE constraint failed'
		);
		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_ai ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_ai' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_ai',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows(
			"SELECT `AUTO_INCREMENT`
			FROM information_schema.tables
			WHERE table_schema = 'wp' AND table_name = 'ddl_drop_pk_ai'"
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_ai' );
	}

	public function test_alter_table_drop_primary_key_temporary_shadow_matches_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE ddl_drop_pk_shadow (id INT PRIMARY KEY, name VARCHAR(20))',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (1, 'persistent')",
				'CREATE TEMPORARY TABLE ddl_drop_pk_shadow (id INT PRIMARY KEY, name VARCHAR(20), KEY name_idx (name))',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (2, 'temporary')",
				'ALTER TABLE ddl_drop_pk_shadow DROP PRIMARY KEY',
				"INSERT INTO ddl_drop_pk_shadow (id, name) VALUES (2, 'temporary_duplicate')",
			)
		);

		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_shadow ORDER BY id, name' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_shadow' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_shadow',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_shadow' );

		$this->runParitySetup( array( 'DROP TEMPORARY TABLE ddl_drop_pk_shadow' ) );
		$this->assertParityRows( 'SELECT id, name FROM ddl_drop_pk_shadow ORDER BY id' );
		$this->assertParityRows( 'SHOW COLUMNS FROM ddl_drop_pk_shadow' );
		$this->assertParityRowColumns(
			'SHOW INDEX FROM ddl_drop_pk_shadow',
			array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part' )
		);
		$this->assertParityRows( 'SHOW CREATE TABLE ddl_drop_pk_shadow' );
	}

	private function create_temporal_write_table( string $table_name, string $nullability = 'NULL', array $extra_definitions = array() ): void {
		$definitions = array_merge(
			array(
				'id INT PRIMARY KEY',
				'd DATE ' . $nullability,
				'tm TIME ' . $nullability,
				'dt DATETIME ' . $nullability,
				'ts TIMESTAMP ' . $nullability,
				'payload VARCHAR(40)',
			),
			$extra_definitions
		);

		$this->runParitySetup(
			array(
				'CREATE TABLE ' . $table_name . " (\n\t"
				. implode( ",\n\t", $definitions )
				. "\n)",
			)
		);
	}

	private function temporal_select_sql( string $table_name ): string {
		return 'SELECT id, d, tm, dt, ts, payload FROM ' . $table_name . ' ORDER BY id';
	}

	private function temporal_join_posts_select_sql(): string {
		return 'SELECT id, d, tm, dt, ts, payload FROM temporal_join_posts ORDER BY id';
	}

	private function assert_temporal_error_leaves_rows( string $sql, string $needle, array $select_queries ): void {
		$this->assertParityErrorContains( $sql, $needle );
		foreach ( $select_queries as $select_query ) {
			$this->assertParityRows( $select_query );
		}
	}

	private function create_duckdb_temporal_mode_table( WP_DuckDB_Driver $driver, string $table_name ): void {
		$driver->query(
			'CREATE TABLE ' . $table_name . ' (
				id INT PRIMARY KEY,
				d DATE NULL,
				dt DATETIME NULL,
				ts TIMESTAMP NULL,
				payload VARCHAR(40)
			)'
		);
	}

	private function assert_duckdb_error_contains( WP_DuckDB_Driver $driver, string $sql, string $needle ): void {
		try {
			$driver->query( $sql );
		} catch ( Throwable $e ) {
			$this->assertStringContainsString( $needle, $e->getMessage() );
			return;
		}

		$this->fail( 'DuckDB query should have failed for SQL: ' . $sql );
	}

	private function assert_no_select_write_stage_tables( WP_DuckDB_Driver $driver ): void {
		$this->assertSame(
			array(),
			$driver->get_connection()->query(
				"SELECT table_name FROM information_schema.tables
				WHERE table_type = 'LOCAL TEMPORARY'
					AND table_name LIKE '\\_\\_wp\\_duckdb\\_%select\\_%' ESCAPE '\\'
				ORDER BY table_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	private function mysql_single_quoted_literal( string $value ): string {
		$backslash = chr( 92 );

		return "'" . strtr(
			$value,
			array(
				$backslash => $backslash . $backslash,
				chr( 0 )   => $backslash . '0',
				chr( 10 )  => $backslash . 'n',
				chr( 13 )  => $backslash . 'r',
				chr( 26 )  => $backslash . 'Z',
				"'"        => $backslash . "'",
				'"'        => $backslash . '"',
			)
		) . "'";
	}

	private function lifecycleTableSql( string $table_name ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			payload LONGTEXT,
			PRIMARY KEY (id),
			UNIQUE KEY name_unique (name),
			KEY payload_prefix (payload(12))
		) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
	}
}
