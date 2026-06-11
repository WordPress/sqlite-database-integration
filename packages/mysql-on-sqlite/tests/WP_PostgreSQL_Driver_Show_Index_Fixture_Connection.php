<?php

/**
 * Fixture connection for PostgreSQL SHOW INDEX catalog tests.
 */
class WP_PostgreSQL_Driver_Show_Index_Fixture_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );

		$this->install_fixture();
	}

	/**
	 * Execute a query against the fixture when the PostgreSQL catalog query is used.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Query parameters.
	 * @return PDOStatement Statement.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( false === strpos( $sql, 'pg_catalog.pg_index' ) ) {
			return parent::query( $sql, $params );
		}

		$fixture_sql    = 'SELECT
			table_name AS "Table",
			non_unique AS "Non_unique",
			key_name AS "Key_name",
			seq_in_index AS "Seq_in_index",
			column_name AS "Column_name",
			collation AS "Collation",
			cardinality AS "Cardinality",
			sub_part AS "Sub_part",
			packed AS "Packed",
			nullable AS "Null",
			index_type AS "Index_type",
			comment AS "Comment",
			index_comment AS "Index_comment",
			visible AS "Visible",
			expression AS "Expression"
		FROM show_index_fixture
		WHERE table_schema = ?
			AND table_name = ?';
		$fixture_params = array( $params[0] ?? '', $params[1] ?? '' );

		if ( isset( $params[2] ) ) {
			$fixture_sql     .= '
			AND key_name = ?';
			$fixture_params[] = $params[2];
		}

		$fixture_sql .= '
		ORDER BY sort_position, CAST(seq_in_index AS INTEGER)';

		return parent::query( $fixture_sql, $fixture_params );
	}

	/**
	 * Install SHOW INDEX fixture rows into the injected PDO.
	 */
	private function install_fixture(): void {
		$pdo = $this->get_pdo();

		$pdo->exec(
			'CREATE TABLE show_index_fixture (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				sort_position INTEGER NOT NULL,
				non_unique TEXT NOT NULL,
				key_name TEXT NOT NULL,
				seq_in_index TEXT NOT NULL,
				column_name TEXT,
				collation TEXT,
				cardinality TEXT,
				sub_part TEXT,
				packed TEXT,
				nullable TEXT NOT NULL,
				index_type TEXT NOT NULL,
				comment TEXT NOT NULL,
				index_comment TEXT NOT NULL,
				visible TEXT NOT NULL,
				expression TEXT
			)'
		);
		$pdo->exec(
			"INSERT INTO show_index_fixture
				(table_schema, table_name, sort_position, non_unique, key_name, seq_in_index, column_name, collation, cardinality, sub_part, packed, nullable, index_type, comment, index_comment, visible, expression)
			VALUES
				('public', 'wptests_options', 1, '0', 'PRIMARY', '1', 'option_id', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_options', 2, '0', 'option_name', '1', 'option_name', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_options', 3, '1', 'autoload', '1', 'autoload', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL),
				('public', 'wptests_posts', 4, '0', 'PRIMARY', '1', 'ID', 'A', '0', NULL, NULL, '', 'BTREE', '', '', 'YES', NULL)"
		);
	}
}
