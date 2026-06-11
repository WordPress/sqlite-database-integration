<?php

/**
 * Fixture connection that accepts PostgreSQL ALTER TABLE syntax in driver tests.
 */
class WP_PostgreSQL_Driver_Alter_Table_Fixture_Connection extends WP_PostgreSQL_Connection {
	/**
	 * Whether DML identity metadata rows are installed.
	 *
	 * @var bool
	 */
	private $has_identity_metadata_fixture = false;

	/**
	 * Number of sequence repair queries executed.
	 *
	 * @var int
	 */
	private $sequence_sync_query_count = 0;

	/**
	 * Constructor.
	 *
	 * @param array[] $identity_metadata_rows Optional fixture identity metadata rows.
	 */
	public function __construct( array $identity_metadata_rows = array() ) {
		parent::__construct( array( 'pdo' => new PDO( 'sqlite::memory:' ) ) );

		if ( ! empty( $identity_metadata_rows ) ) {
			$this->install_information_schema_marker();
			$this->install_identity_metadata_fixture( $identity_metadata_rows );
			$this->has_identity_metadata_fixture = true;
		}
	}

	/**
	 * Execute a query against PostgreSQL test fixtures when needed.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Query parameters.
	 * @return PDOStatement Statement.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->has_identity_metadata_fixture && false !== strpos( $sql, 'pg_catalog.pg_get_serial_sequence' ) ) {
			return parent::query(
				'SELECT
					column_name,
					data_type,
					is_identity,
					column_default,
					mysql_column_type,
					mysql_extra,
					sequence_schema,
					sequence_name
				FROM dml_identity_metadata_fixture
				WHERE table_schema = ?
					AND table_name = ?
				ORDER BY ordinal_position',
				array( $params[0] ?? '', $params[1] ?? '' )
			);
		}

		if ( $this->has_identity_metadata_fixture && false !== strpos( $sql, 'pg_catalog.setval' ) ) {
			++$this->sequence_sync_query_count;
			return parent::query( 'SELECT 1' );
		}

		if ( 0 === strpos( $sql, 'ALTER TABLE ' ) ) {
			return parent::query( 'SELECT 1 WHERE 0 = 1' );
		}

		return parent::query( $sql, $params );
	}

	/**
	 * Get the number of sequence repair queries executed.
	 *
	 * @return int Sequence repair query count.
	 */
	public function get_sequence_sync_query_count(): int {
		return $this->sequence_sync_query_count;
	}

	/**
	 * Install the information_schema marker used by the SQLite test shim.
	 */
	private function install_information_schema_marker(): void {
		$pdo = $this->get_pdo();
		$pdo->exec( "ATTACH DATABASE ':memory:' AS information_schema" );
		$pdo->exec( 'CREATE TABLE information_schema.columns (table_schema TEXT)' );
	}

	/**
	 * Install identity metadata rows.
	 *
	 * @param array[] $identity_metadata_rows Fixture identity metadata rows.
	 */
	private function install_identity_metadata_fixture( array $identity_metadata_rows ): void {
		parent::query(
			'CREATE TABLE dml_identity_metadata_fixture (
				table_schema TEXT NOT NULL,
				table_name TEXT NOT NULL,
				column_name TEXT NOT NULL,
				ordinal_position INTEGER NOT NULL,
				data_type TEXT NOT NULL,
				is_identity TEXT NOT NULL,
				column_default TEXT,
				mysql_column_type TEXT,
				mysql_extra TEXT NOT NULL,
				sequence_schema TEXT,
				sequence_name TEXT
			)'
		);

		foreach ( $identity_metadata_rows as $row ) {
			parent::query(
				'INSERT INTO dml_identity_metadata_fixture
					(table_schema, table_name, column_name, ordinal_position, data_type, is_identity, column_default, mysql_column_type, mysql_extra, sequence_schema, sequence_name)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				array(
					$row['table_schema'] ?? 'public',
					$row['table_name'],
					$row['column_name'],
					$row['ordinal_position'] ?? 1,
					$row['data_type'] ?? 'bigint',
					$row['is_identity'] ?? 'YES',
					$row['column_default'] ?? null,
					$row['mysql_column_type'] ?? 'bigint(20)',
					$row['mysql_extra'] ?? 'auto_increment',
					$row['sequence_schema'] ?? 'public',
					$row['sequence_name'],
				)
			);
		}
	}
}
