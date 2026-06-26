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

	public function test_regexp_and_not_regexp_match_sqlite(): void {
		$this->runParitySetup(
			array(
				'CREATE TABLE options (option_name VARCHAR(100))',
				"INSERT INTO options VALUES ('rss_123'), ('transient')",
			)
		);

		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name REGEXP '^rss_.+$' ORDER BY option_name" );
		$this->assertParityRows( "SELECT option_name FROM options WHERE option_name NOT REGEXP '^rss_.+$' ORDER BY option_name" );
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
				ROW_FORMAT, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, MAX_DATA_LENGTH,
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
			'Rows',
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
}
