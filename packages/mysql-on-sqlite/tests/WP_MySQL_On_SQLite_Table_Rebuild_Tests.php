<?php

use PHPUnit\Framework\TestCase;

class WP_MySQL_On_SQLite_Table_Rebuild_Tests extends TestCase {

	/** @var PDO */
	private $sqlite;

	/** @var WP_MySQL_On_SQLite */
	private $engine;

	public function setUp(): void {
		$pdo_class    = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$this->sqlite = new $pdo_class( 'sqlite::memory:' );
		$this->engine = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => $this->sqlite )
		);
	}

	/**
	 * @dataProvider stringTableRebuilds
	 */
	public function testRebuildPreservesLegacyStringValues( string $type, string $sqlite_type, string $query, string $column ): void {
		$this->createLegacyStringTable( $type, $sqlite_type );
		$before = $this->getTableValues( 'Pay`load' );

		$result = $this->engine->query( sprintf( $query, $type ) );
		if ( $result->columnCount() > 0 ) {
			// Administration statements report errors in their result rows.
			$this->assertSame( array( 'status' ), array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'Msg_type' ) );
		}

		$this->assertSame( $before, $this->getTableValues( $column ) );
		$quoted_column = $this->engine->get_connection()->quote_identifier( $column );
		$this->assertSame(
			array( strtolower( $sqlite_type ) ),
			$this->sqlite->query(
				"SELECT DISTINCT typeof($quoted_column) FROM t WHERE $quoted_column IS NOT NULL"
			)->fetchAll( PDO::FETCH_COLUMN )
		);
	}

	public static function stringTableRebuilds(): array {
		$queries = array(
			'add'          => array( 'ALTER TABLE t ADD COLUMN added TEXT', 'Pay`load' ),
			'modify'       => array( 'ALTER TABLE t MODIFY COLUMN `pay``LOAD` %s', 'pay`LOAD' ),
			'change'       => array( 'ALTER TABLE t CHANGE COLUMN `pay``LOAD` `New``Payload` %s', 'New`Payload' ),
			'case rename'  => array( 'ALTER TABLE t CHANGE COLUMN `pay``LOAD` `PAY``LOAD` %s', 'PAY`LOAD' ),
			'optimize'     => array( 'OPTIMIZE TABLE t', 'Pay`load' ),
			'repair'       => array( 'REPAIR TABLE t', 'Pay`load' ),
			'drop primary' => array( 'DROP INDEX `PRIMARY` ON t', 'Pay`load' ),
		);
		$types   = array(
			'BLOB' => array( 'BINARY(32)', 'VARBINARY(32)', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB' ),
			'TEXT' => array( 'CHAR(32)', 'VARCHAR(32)', 'NCHAR(32)', 'NVARCHAR(32)', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT' ),
		);
		$cases   = array();
		foreach ( $types as $sqlite_type => $mysql_types ) {
			foreach ( $mysql_types as $type ) {
				foreach ( $queries as $name => $query ) {
					$cases[ "$type $name" ] = array( $type, $sqlite_type, $query[0], $query[1] );
				}
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider stringColumnTypeChanges
	 */
	public function testRebuildUsesChangedColumnType( string $old_type, string $new_type, string $storage_class ): void {
		$this->engine->exec( "CREATE TABLE t (payload $old_type)" );
		$this->engine->exec( "INSERT INTO t VALUES ('000123'), ('abc'), (NULL)" );
		$this->engine->exec( "ALTER TABLE t CHANGE COLUMN payload renamed $new_type" );
		$this->assertSame(
			array(
				array(
					'hex'  => '303030313233',
					'type' => $storage_class,
				),
				array(
					'hex'  => '616263',
					'type' => $storage_class,
				),
				array(
					'hex'  => '',
					'type' => 'null',
				),
			),
			$this->sqlite->query( 'SELECT hex(renamed) AS hex, typeof(renamed) AS type FROM t ORDER BY rowid' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public static function stringColumnTypeChanges(): array {
		return array(
			'text to binary' => array( 'TEXT', 'VARBINARY(32)', 'blob' ),
			'blob to text'   => array( 'BLOB', 'TEXT', 'text' ),
		);
	}

	/**
	 * @dataProvider textBackedColumnValues
	 */
	public function testRebuildPreservesTextBackedColumnValues( string $type, string $value ): void {
		$this->engine->exec( "CREATE TABLE t (payload $type)" );
		$this->sqlite->exec( 'DROP TABLE t' );
		$this->sqlite->exec( 'CREATE TABLE t (payload TEXT)' );
		$this->sqlite->exec( "INSERT INTO t VALUES (x'" . bin2hex( $value ) . "')" );

		$this->engine->exec( 'ALTER TABLE t ADD COLUMN note TEXT' );

		$this->assertSame( $value, $this->sqlite->query( 'SELECT payload FROM t' )->fetchColumn() );
		$this->assertSame( 'text', $this->sqlite->query( 'SELECT typeof(payload) FROM t' )->fetchColumn() );
	}

	public static function textBackedColumnValues(): array {
		return array(
			'json'      => array( 'JSON', '{"name":"žluťoučký"}' ),
			'enum'      => array( "ENUM('abc','def')", 'abc' ),
			'set'       => array( "SET('a','b')", 'a,b' ),
			'date'      => array( 'DATE', '2024-02-29' ),
			'time'      => array( 'TIME(3)', '12:34:56.123' ),
			'datetime'  => array( 'DATETIME(3)', '2024-02-29 12:34:56.123' ),
			'timestamp' => array( 'TIMESTAMP(3)', '2024-02-29 12:34:56.123' ),
			'year'      => array( 'YEAR', '2024' ),
			'geometry'  => array( 'GEOMETRY', 'POINT(1 2)' ),
		);
	}

	/**
	 * @dataProvider qualifiedTableRebuilds
	 */
	public function testRebuildUsesMainDatabaseMetadata( string $query ): void {
		$this->createLegacyStringTable( 'BLOB', 'BLOB' );
		$before = $this->getTableValues( 'Pay`load' );

		$this->engine->exec( 'USE information_schema' );
		$result = $this->engine->query( $query );
		if ( $result->columnCount() > 0 ) {
			$this->assertSame( array( 'status' ), array_column( $result->fetchAll( PDO::FETCH_ASSOC ), 'Msg_type' ) );
		}

		$this->assertSame( $before, $this->getTableValues( 'Pay`load' ) );
		$this->assertSame(
			array( 'blob' ),
			$this->sqlite->query( 'SELECT DISTINCT typeof(`Pay``load`) FROM t WHERE `Pay``load` IS NOT NULL' )->fetchAll( PDO::FETCH_COLUMN )
		);
	}

	public static function qualifiedTableRebuilds(): array {
		return array(
			'alter'        => array( 'ALTER TABLE wp.t ADD COLUMN added TEXT' ),
			'optimize'     => array( 'OPTIMIZE TABLE wp.t' ),
			'repair'       => array( 'REPAIR TABLE wp.t' ),
			'drop primary' => array( 'DROP INDEX `PRIMARY` ON wp.t' ),
		);
	}

	/**
	 * @dataProvider stringStorageTypes
	 */
	public function testRebuildUsesTemporaryColumnMetadata( string $type ): void {
		$main_type = 'BLOB' === $type ? 'TEXT' : 'BLOB';
		$this->engine->exec( "CREATE TABLE t (`Pay``load` $main_type)" );
		$this->engine->exec( "INSERT INTO t VALUES ('main table')" );
		$this->createLegacyStringTable( $type, $type, true );
		$before = $this->getTableValues( 'Pay`load' );

		$this->engine->exec( 'ALTER TABLE t ADD COLUMN added TEXT' );

		$this->assertSame( $before, $this->getTableValues( 'Pay`load' ) );
		$this->assertSame( strtolower( $type ), $this->sqlite->query( 'SELECT typeof(`Pay``load`) FROM temp.t WHERE id = 3' )->fetchColumn() );
		$this->assertSame( 'main table', $this->sqlite->query( 'SELECT `Pay``load` FROM main.t' )->fetchColumn() );
		$this->assertSame( strtolower( $main_type ), $this->sqlite->query( 'SELECT typeof(`Pay``load`) FROM main.t' )->fetchColumn() );
	}

	/**
	 * @dataProvider stringStorageTypes
	 */
	public function testFailedRebuildPreservesLegacyValuesAndSchema( string $type ): void {
		$this->createLegacyStringTable( $type, $type );
		$this->sqlite->exec( 'UPDATE t SET note = NULL WHERE id = 3' );
		$before = $this->getTableValues( 'Pay`load' );
		$schema = $this->sqlite->query( "SELECT sql FROM sqlite_master WHERE name = 't'" )->fetchColumn();

		$exception = null;
		try {
			$this->engine->exec( 'ALTER TABLE t MODIFY COLUMN note TEXT NOT NULL' );
		} catch ( WP_MySQL_On_SQLite_Exception $e ) {
			$exception = $e;
		}
		$this->assertInstanceOf( WP_MySQL_On_SQLite_Exception::class, $exception );
		$this->assertStringContainsString( 'NOT NULL constraint failed', $exception->getMessage() );
		$this->assertSame( $before, $this->getTableValues( 'Pay`load' ) );
		$this->assertSame( $schema, $this->sqlite->query( "SELECT sql FROM sqlite_master WHERE name = 't'" )->fetchColumn() );
		$this->assertSame( 'text', $this->sqlite->query( 'SELECT typeof(`Pay``load`) FROM t WHERE id = 3' )->fetchColumn() );
		$this->assertSame(
			'YES',
			$this->engine->query( "SELECT is_nullable FROM information_schema.columns WHERE table_name = 't' AND column_name = 'note'" )->fetchColumn()
		);
		$this->assertSame( 0, (int) $this->sqlite->query( "SELECT COUNT(*) FROM sqlite_master WHERE name GLOB '_wp_sqlite_tmp_*'" )->fetchColumn() );

		$this->engine->exec( 'ALTER TABLE t ADD COLUMN added TEXT' );
		$this->assertSame( $before, $this->getTableValues( 'Pay`load' ) );
	}

	public static function stringStorageTypes(): array {
		return array(
			'blob' => array( 'BLOB' ),
			'text' => array( 'TEXT' ),
		);
	}

	/**
	 * @dataProvider legacyMetadataStates
	 */
	public function testTemporalDefaultMigrationPreservesLegacyStringValues( bool $has_metadata, string $type ): void {
		if ( $has_metadata ) {
			$this->engine->exec( "CREATE TABLE t (id INT, payload $type, created_at TIMESTAMP DEFAULT 0)" );
			$this->sqlite->exec( 'DROP TABLE t' );
		} else {
			$this->sqlite->exec(
				'CREATE TABLE _mysql_data_types_cache (
					`table` TEXT NOT NULL,
					column_or_index TEXT NOT NULL,
					mysql_type TEXT NOT NULL,
					PRIMARY KEY (`table`, column_or_index)
				)'
			);
			$this->sqlite->exec(
				"INSERT INTO _mysql_data_types_cache VALUES
					('t', 'id', 'int'), ('t', 'payload', '" . strtolower( $type ) . "'), ('t', 'created_at', 'timestamp')"
			);
		}
		$this->sqlite->exec( "CREATE TABLE t (id INTEGER, payload $type, created_at TEXT DEFAULT 0)" );
		$this->sqlite->exec( "INSERT INTO t (id, payload) VALUES (1, CAST(x'6100FF' AS TEXT)), (2, x'00FF80'), (3, NULL)" );
		$this->sqlite->exec(
			sprintf(
				"UPDATE %s SET value = '0.0.0' WHERE name = '%s'",
				WP_MySQL_On_SQLite::GLOBAL_VARIABLES_TABLE_NAME,
				WP_MySQL_On_SQLite::DRIVER_VERSION_VARIABLE_NAME
			)
		);

		$this->engine = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => $this->sqlite )
		);

		$this->assertSame(
			array(
				array(
					'hex'        => '6100FF',
					'type'       => strtolower( $type ),
					'created_at' => '0000-00-00 00:00:00',
				),
				array(
					'hex'        => '00FF80',
					'type'       => strtolower( $type ),
					'created_at' => '0000-00-00 00:00:00',
				),
				array(
					'hex'        => '',
					'type'       => 'null',
					'created_at' => '0000-00-00 00:00:00',
				),
			),
			$this->sqlite->query( 'SELECT hex(payload) AS hex, typeof(payload) AS type, created_at FROM t ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame( SQLITE_DRIVER_VERSION, $this->engine->get_saved_driver_version() );
		$this->assertSame(
			'0000-00-00 00:00:00',
			$this->engine->query( "SELECT column_default FROM information_schema.columns WHERE table_name = 't' AND column_name = 'created_at'" )->fetchColumn()
		);
	}

	public static function legacyMetadataStates(): array {
		return array(
			'previously upgraded blob' => array( true, 'BLOB' ),
			'previously upgraded text' => array( true, 'TEXT' ),
			'legacy metadata blob'     => array( false, 'BLOB' ),
			'legacy metadata text'     => array( false, 'TEXT' ),
		);
	}

	private function createLegacyStringTable( string $type, string $sqlite_type, bool $temporary = false ): void {
		$temporary_keyword = $temporary ? 'TEMPORARY' : '';
		$this->engine->exec( "CREATE $temporary_keyword TABLE t (id INT PRIMARY KEY, `Pay``load` $type, note TEXT)" );

		// Earlier upgrades retained non-STRICT tables and their original storage classes.
		$this->sqlite->exec( 'DROP TABLE t' );
		$this->sqlite->exec( "CREATE $temporary_keyword TABLE t (id INT PRIMARY KEY, `Pay``load` $sqlite_type, note TEXT)" );
		$this->sqlite->exec(
			"INSERT INTO t (rowid, id, `Pay``load`, note) VALUES
				(11, 1, NULL, '000123'),
				(12, 2, '', '000123'),
				(13, 3, 'abc', '000123'),
				(14, 4, '000123', '000123'),
				(15, 5, 'žluťoučký', '000123'),
				(16, 6, CAST(x'610062' AS TEXT), '000123'),
				(17, 7, CAST(x'FF80FE61' AS TEXT), '000123'),
				(18, 8, x'00FF80FE61', '000123'),
				(19, 9, 123, '000123'),
				(20, 10, 1.25, '000123')"
		);
	}

	private function getTableValues( string $column ): array {
		$quoted_column = $this->engine->get_connection()->quote_identifier( $column );
		return $this->sqlite->query(
			"SELECT rowid, id, hex($quoted_column), $quoted_column IS NULL, note, typeof(note) FROM t ORDER BY rowid"
		)->fetchAll( PDO::FETCH_NUM );
	}
}
